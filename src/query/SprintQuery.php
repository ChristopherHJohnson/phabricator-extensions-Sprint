<?php

final class SprintQuery  {

  private $viewer;
  private $project;
  private $project_phid;


  public function setProject ($project) {
    $this->project = $project;
    return $this;
  }

  public function setViewer ($viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  public function setPHID ($project_phid) {
    $this->project_phid = $project_phid;
    return $this;
  }

  public function getAuxFields() {
    $field_list = PhabricatorCustomField::getObjectFields($this->project, PhabricatorCustomField::ROLE_EDIT);
    $field_list->setViewer($this->viewer);
    $field_list->readFieldsFromStorage($this->project);
    $aux_fields = $field_list->getFields();
    return $aux_fields;
  }

  public function getStartDate($aux_fields) {
    $start = idx($aux_fields, 'isdc:sprint:startdate')
        ->getProxy()->getFieldValue();
    return $start;
  }

  public function getEndDate($aux_fields) {
    $end = idx($aux_fields, 'isdc:sprint:enddate')
        ->getProxy()->getFieldValue();
    return $end;
  }

  public function getTasks() {
    $tasks = id(new ManiphestTaskQuery())
        ->setViewer($this->viewer)
        ->withAnyProjects(array($this->project->getPHID()))
        ->execute();
    return $tasks;
  }

  public function getXactions($tasks) {
    $task_phids = mpull($tasks, 'getPHID');

    $xactions = id(new ManiphestTransactionQuery())
        ->setViewer($this->viewer)
        ->withObjectPHIDs($task_phids)
        ->execute();
    return $xactions;
  }

  public function checkNull($start, $end, $tasks) {
    if (!$start OR !$end) {
      throw new BurndownException("This project is not set up for Burndowns, "
          . "make sure it has 'Sprint' in the name, and then edit it to add the "
          . "sprint start and end date.");
    }

    if (!$tasks) {
      throw new BurndownException("This project has no tasks.");
    }
  }

  public function getXActionObj () {
    $table = new ManiphestTransaction();
    return $table;
  }

  public function getXActionConn () {
    $conn = $this->getXActionObj()->establishConnection('r');
    return $conn;
  }

  public function getJoins() {

    $joins = '';
    if ($this->project_phid) {
      $joins = qsprintf(
          $this->getXactionConn(),
          'JOIN %T t ON x.objectPHID = t.phid
          JOIN %T p ON p.src = t.phid AND p.type = %d AND p.dst = %s',
          id(new ManiphestTask())->getTableName(),
          PhabricatorEdgeConfig::TABLE_NAME_EDGE,
          PhabricatorProjectObjectHasProjectEdgeType::EDGECONST,
          $this->project_phid);
    }
    return $joins;
  }

  public function getXactionData() {
    $data = queryfx_all(
        $this->getXactionConn(),
        'SELECT x.objectPHID, x.oldValue, x.newValue, x.dateCreated FROM %T x %Q
        WHERE transactionType = %s
        ORDER BY x.dateCreated ASC',
        $this->getXActionObj()->getTableName(),
        $this->getJoins(),
        ManiphestTransaction::TYPE_STATUS);
    return $data;
 }

  public function getEvents($xactions) {
    $scope_phids = array($this->project->getPHID());
    $events = $this->extractEvents($xactions, $scope_phids);
    return $events;
  }

  public function extractEvents($xactions, array $scope_phids) {
    assert_instances_of($xactions, 'ManiphestTransaction');

    $scope_phids = array_fuse($scope_phids);

    $events = array();
    foreach ($xactions as $xaction) {
      $old = $xaction->getOldValue();
      $new = $xaction->getNewValue();

      $event_type = null;
      switch ($xaction->getTransactionType()) {
        case ManiphestTransaction::TYPE_STATUS:
          $old_is_closed = ($old === null) ||
              ManiphestTaskStatus::isClosedStatus($old);
          $new_is_closed = ManiphestTaskStatus::isClosedStatus($new);

          if ($old_is_closed == $new_is_closed) {
            // This was just a status change from one open status to another,
            // or from one closed status to another, so it's not an events we
            // care about.
            break;
          }
          if ($old === null) {
            // This would show as "reopened" even though it's when the task was
            // created so we skip it. Instead we will use the title for created
            // events
            break;
          }

          if ($new_is_closed) {
            $event_type = 'close';
          } else {
            $event_type = 'reopen';
          }
          break;

        case ManiphestTransaction::TYPE_TITLE:
          if ($old === null)
          {
            $event_type = 'create';
          }
          break;

        // Project changes are "core:edge" transactions
        case PhabricatorTransactions::TYPE_EDGE:

          // We only care about ProjectEdgeType
          if (idx($xaction->getMetadata(), 'edge:type') !==
              PhabricatorProjectObjectHasProjectEdgeType::EDGECONST)
            break;

          $old = ipull($old, 'dst');
          $new = ipull($new, 'dst');

          $in_old_scope = array_intersect_key($scope_phids, $old);
          $in_new_scope = array_intersect_key($scope_phids, $new);

          if ($in_new_scope && !$in_old_scope) {
            $event_type = 'task-add';
          } else if ($in_old_scope && !$in_new_scope) {
            // NOTE: We will miss some of these events, becuase we are only
            // examining tasks that are currently in the project. If a task
            // is removed from the project and not added again later, it will
            // just vanish from the chart completely, not show up as a
            // scope contraction. We can't do better until the Facts application
            // is avialable without examining *every* task.
            $event_type = 'task-remove';
          }
          break;

        case PhabricatorTransactions::TYPE_CUSTOMFIELD:
          if ($xaction->getMetadataValue('customfield:key') == 'isdc:sprint:storypoints') {
            // POINTS!
            $event_type = 'points';
          }
          break;

        default:
          // This is something else (comment, subscription change, etc) that
          // we don't care about for now.
          break;
      }

      // If we found some kind of events that we care about, stick it in the
      // list of events.
      if ($event_type !== null) {
        $events[] = array(
            'transactionPHID' => $xaction->getPHID(),
            'epoch' => $xaction->getDateCreated(),
            'key'   => $xaction->getMetadataValue('customfield:key'),
            'type'  => $event_type,
            'title' => $xaction->getTitle(),
        );
      }
    }

    // Sort all events chronologically.
    $events = isort($events, 'epoch');

    return $events;
  }
}
