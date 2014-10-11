<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 * This script is distributed under the GNU General Public License 2 or later. 
 *
 * Manager for assignment activities
 *
 * @filesource  assignment_mgr.class.php
 * @package   TestLink
 * @author    Francisco Mancardi
 * @copyright   2007-2014, TestLink community 
 * @link    http://www.testlink.org
 * 
 * @internal revisions
 */
 
/**
 * class manage assignment users for testing
 * @package   TestLink
 */ 
class assignment_mgr extends tlObjectWithDB
{

  function __construct(&$db) 
  {
    parent::__construct($db);
  }

  /*
   $key_field: contains the filename that has to be used as the key of
               the returned hash.    
  */
  function get_available_types($key_field='description') 
  {
    static $hash_types;
    if (!$hash_types)
    {
      $sql = "SELECT * FROM {$this->tables['assignment_types']}";
      $hash_types = $this->db->fetchRowsIntoMap($sql,$key_field);
    }
    return $hash_types;
  }

  /*
   $key_field: contains the name column that has to be used as the key of
               the returned hash.    
  */
  function get_available_status($key_field='description') 
  {
    static $hash_types;
    if (!$hash_types)
    {
      $sql = " SELECT * FROM {$this->tables['assignment_status']} "; 
      $hash_types = $this->db->fetchRowsIntoMap($sql,$key_field);
    }
    
    return $hash_types;
  }

  // $feature_id can be an scalar or an array
  function delete_by_feature_id($feature_id) 
  {
    if( is_array($feature_id) )
    {
      $feature_id_list = implode(",",$feature_id);
      $where_clause = " WHERE feature_id IN ($feature_id_list) ";
    }
      else
    {
      $where_clause = " WHERE feature_id={$feature_id}";
    }
    $sql = " DELETE FROM {$this->tables['user_assignments']}  {$where_clause}"; 
    $result = $this->db->exec_query($sql);
  }
  
  /**
   * Delete the user assignments for a given build.
   * 
   * @author Andreas Simon
   * @param int $build_id The ID of the build for which the user assignments shall be deleted.
   * @param int $delete_all_types If true, all assignments regardless of type will be deleted,
   *                              else (default) only tester assignments.
   */
  function delete_by_build_id($build_id, $delete_all_types = false) 
  {
    $type_sql = "";
    
    if (!$delete_all_types) 
    {
      $types = $this->get_available_types();
      $tc_execution_type = $types['testcase_execution']['id'];
      $type_sql = " AND type = {$tc_execution_type} ";
    }
    
    $sql = " DELETE FROM {$this->tables['user_assignments']} " .
           " WHERE build_id = " . intval($build_id) . " {$type_sql} ";
    
    $this->db->exec_query($sql);
  }

  // delete assignments by feature id and build_id
  function delete_by_feature_id_and_build_id($feature_map) 
  {
    $feature_id_list = implode(",",array_keys($feature_map));
    $where_clause = " WHERE feature_id IN ($feature_id_list) ";
      
    $sql = " DELETE FROM {$this->tables['user_assignments']}  {$where_clause} ";
    
    // build_id is the same for all entries because of assignment form
    // -> skip foreach after first iteration
    $build_id = 0;
    foreach ($feature_map as $key => $feature) 
    {
      $build_id = $feature['build_id'];
      break;
    }
    
    $sql .= " AND build_id = {$build_id} ";
    $result = $this->db->exec_query($sql);
  }

  /**
   * $items array of signature
   * signature = array('type' => ,'feature_id' =>,'user_id' =>, 'build_id' => )
   *
   */
  function deleteBySignature($items) 
  {
    $debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;

    foreach($items as $signature)
    {
      $sql = " DELETE FROM {$this->tables['user_assignments']} WHERE 1=1 ";
      foreach($signature as $column => $val)
      {
        $sql .= " AND $column = " . intval($val);
      }  
      $result = $this->db->exec_query($sql);
    }  
  }


  /**
    * 
    * @param $feature_map
    * $feature_map['feature_id']['user_id']
    * $feature_map['feature_id']['type']
    * $feature_map['feature_id']['status']
    * $feature_map['feature_id']['assigner_id']
    * $feature_map['feature_id']['build_id']
    * 
    *
    * Need to manage situation where user_id = 0 is passed
    * I will IGNORE IT
    *
    * @internal revisions
    */
  function assign($feature_map) 
  {
    $debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
    $ret = array();
    $types = $this->get_available_types();
    $safe = null;
    foreach($feature_map as $feature_id => $elem)
    {
      // Check if exists before adding
      $safe['user_id'] = intval($elem['user_id']);
      $check = "/* $debugMsg */ ";
      $check .= " SELECT id FROM {$this->tables['user_assignments']} " .
                " WHERE feature_id = " . intval($feature_id) .
                " AND build_id = " . intval($elem['build_id']) .
                " AND type = " . intval($elem['type']) .
                " AND user_id = " . $safe['user_id'];

      $rs = $this->db->get_recordset($check);
      if( is_null($rs) || count($rs) == 0 )
      {
        if($safe['user_id'] > 0)
        {
          $sql = "INSERT INTO {$this->tables['user_assignments']} " .
                 "(feature_id,user_id,assigner_id,type,status,creation_ts";
                      
          $values = "VALUES({$feature_id},{$elem['user_id']},{$elem['assigner_id']}," .
                    "{$elem['type']},{$elem['status']},";
          $values .= (isset($elem['creation_ts']) ? $elem['creation_ts'] : $this->db->db_now());                   
          
          if(isset($elem['deadline_ts']) )
          {
            $sql .=",deadline_ts";
            $values .="," . $elem['deadline_ts']; 
          }     
          
          if (isset($elem['build_id'])) 
          {
            $sql .= ",build_id";
            $values .= "," . intval($elem['build_id']);
          }
          else
          {
            if($elem['type'] == $types['testcase_execution']['id'])
            {
              throw new Exception("Error Processing Request - BUILD ID is Mandatory");
            }  
          }  
          
          $sql .= ") " . $values . ")";
          tLog(__METHOD__ . '::' . $sql,"DEBUG");
          $this->db->exec_query($sql);
          $ret[] = $sql;
        }  
      }  
    }
    return $ret;
  }
  

  /**
   * 
   * @param $feature_map
   * $feature_map: key   => feature_id
   *               value => hash with optional keys 
   *                        that have the same name of user_assignment fields
   * 
   * @internal revisions
   */
  function update($feature_map) 
  {
    foreach($feature_map as $feature_id => $elem)
    {
      $sepa = "";
      $sql = "UPDATE {$this->tables['user_assignments']} SET ";
      $simple_fields = array('user_id','assigner_id','type','status');
      $date_fields = array('deadline_ts','creation_ts');  
    
      foreach($simple_fields as $idx => $field)
      {
        if(isset($elem[$field]))
        {
          $sql .= $sepa . "$field={$elem[$field]} ";
          $sepa=",";
        }
      }
      
      foreach($date_fields as $idx => $field)
      {
        if(isset($elem[$field]))
        {
          $sql .= $sepa . "$field=" . $elem[$field] . " ";
          $sepa = ",";
        }
      }
      
      $sql .= "WHERE feature_id={$feature_id} AND build_id={$elem['build_id']}";
      
      $this->db->exec_query($sql);
    }
  }
  
  /**
   * Get the number of assigned users for a given build ID.
   * @param int $build_id ID of the build to check
   * @param int $count_all_types if true, all assignments will be counted, otherwise
   *                             only tester assignments
   * @param int $user_id if given, user ID for which the assignments per build shall be counted
   * @return int $count Number of assignments
   */
  function get_count_of_assignments_for_build_id($build_id, $count_all_types = false, $user_id = 0) 
  {
    $count = 0;
    
    $types = $this->get_available_types();
    $tc_execution_type = $types['testcase_execution']['id'];
    $type_sql = ($count_all_types) ? "" : " AND type = {$tc_execution_type} ";
      
    $user_sql = ($user_id && is_numeric($user_id)) ? "AND user_id = {$user_id} " : "";
    
    $sql = " SELECT COUNT(id) AS count FROM {$this->tables['user_assignments']} " .
           " WHERE build_id = {$build_id} {$user_sql} {$type_sql} ";
      
    $count = $this->db->fetchOneValue($sql);
      
    return $count;
  }
  
  /**
   * Get count of assigned, but not run testcases per build (and optionally user).
   * @param int $build_id
   * @param bool $all_types
   * @param int $user_id if set and != 0, counts only the assignments for the given user 
   *
   * @internal revisions
   */
  function get_not_run_tc_count_per_build($build_id, $all_types = false, $user_id = 0) 
  {
    $count = 0;
    
    $types = $this->get_available_types();
    $tc_execution_type = $types['testcase_execution']['id'];
    $type_sql = ($all_types) ? "" : " AND UA.type = {$tc_execution_type} ";
    $user_sql = ($user_id && is_numeric($user_id)) ? "AND UA.user_id = {$user_id} " : "";
    
    $sql = " SELECT UA.id as assignment_id,UA.user_id,TPTCV.testplan_id," .
           " TPTCV.platform_id,BU.id AS BUILD_ID,E.id AS EXECID, E.status " .
           " FROM {$this->tables['user_assignments']} UA " .
           " JOIN {$this->tables['builds']}  BU ON UA.build_id = BU.id " .
           " JOIN {$this->tables['testplan_tcversions']} TPTCV " .
           "     ON TPTCV.testplan_id = BU.testplan_id " .
           "     AND TPTCV.id = UA.feature_id " .
           " LEFT OUTER JOIN {$this->tables['executions']} E " .
           "     ON E.testplan_id = TPTCV.testplan_id " . 
           "     AND E.tcversion_id = TPTCV.tcversion_id " .
           "     AND E.platform_id = TPTCV.platform_id " .
           "     AND E.build_id = UA.build_id " .
           " WHERE UA.build_id = {$build_id} AND E.status IS NULL {$type_sql} {$user_sql} ";       
       
       
    if (isset($build_id) && is_numeric($build_id)) {
      $count = count($this->db->fetchRowsIntoMap($sql, 'assignment_id'));
    }
    
    return $count;
  }
  
  /**
   * Copy the test case execution assignments for a test plan
   * from one build to another.
   * During copying of assignments, the assigner id can be updated if an ID is passed
   * and the timestamp will be updated.
   * 
   * @author Andreas Simon
   * @param int $source_build_id ID of the build to copy the assignments from
   * @param int $target_build_id ID of the target build to which the assignments will be copied
   * @param int $assigner_id will be set as assigner ID of the new assignments if != 0,
   *                         otherwise old assigner ID will be copied 
   * @param bool $keep_old_assignments if true, existing assignments in target build will be kept,
   *                            otherwise (default) every existing tester assignment will be deleted
   * @param int $copy_all_types If true, all assignments will be copied regardless of type, 
   *                            else only tester assignments will be copied (default).
   */
  function copy_assignments($source_build_id, $target_build_id, $assigner_id = 0,
                            $keep_old_assignments = false, $copy_all_types = false) 
  {
    $ua = $this->tables['user_assignments'];
    $creation_ts = $this->db->db_now();
    $types = $this->get_available_types();
    $tc_execution_type = $types['testcase_execution']['id'];
    $delete_all_types = $copy_all_types;
      
    $type_sql = ($copy_all_types) ? "" : " AND type = {$tc_execution_type} ";
    $user_sql = (is_numeric($assigner_id) && $assigner_id != 0) ? $assigner_id : "assigner_id";

    if ($keep_old_assignments == false) {
      // delete the old tester assignments in target builds if there are any
      $this->delete_by_build_id($target_build_id, $delete_all_types);
    }
    
    $sql = " INSERT INTO {$ua} " .
           " (type, feature_id, user_id, deadline_ts, " .
           " assigner_id, creation_ts, status, build_id) " .
           " SELECT type, feature_id, user_id, deadline_ts, " . 
           " {$user_sql}, {$creation_ts}, status, {$target_build_id} " .
           " FROM {$ua} " .
           " WHERE build_id = {$source_build_id} {$type_sql} ";
    
    $this->db->exec_query($sql);
  } 
  

  /**
   * get hash with build id and amount of test cases assigned to testers
   * 
   * @author Francisco Mancardi
   * @param mixed $buildID can be single value or array of build ID.
   */
  function getExecAssignmentsCountByBuild($buildID)
  {
    $debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
    $rs = null;
    $types = $this->get_available_types();
    $execAssign = $types['testcase_execution']['id'];
      
    $sql =  "/* $debugMsg */ ".
            " SELECT COUNT(id) AS qty, build_id " . 
            " FROM {$this->tables['user_assignments']} " .
            " WHERE build_id IN ( " . implode(",",(array)$buildID) . " ) " .
            " AND type = {$execAssign} " .
            " GROUP BY build_id ";
      $rs = $this->db->fetchRowsIntoMap($sql,'build_id');
      
    return $rs;
  }


  /**
   * get hash with build id and amount of test cases assigned to testers,
   * but NOT EXECUTED.
   * 
   * 
   * @author Francisco Mancardi
   * @param mixed $buildID can be single value or array of build ID.
   */
  function getNotRunAssignmentsCountByBuild($buildID)
  {
    $debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
    $rs = null;
    $types = $this->get_available_types();
    $execAssign = $types['testcase_execution']['id'];

    $sql =  "/* $debugMsg */ ".
            " SELECT count(0) as qty, UA.build_id ".
            " FROM {$this->tables['user_assignments']} UA " .
            " JOIN {$this->tables['builds']}  BU ON UA.build_id = BU.id " .
            " JOIN {$this->tables['testplan_tcversions']} TPTCV " .
            "     ON TPTCV.testplan_id = BU.testplan_id " .
            "     AND TPTCV.id = UA.feature_id " .
            " LEFT OUTER JOIN {$this->tables['executions']} E " .
            "     ON E.testplan_id = TPTCV.testplan_id " . 
            "     AND E.tcversion_id = TPTCV.tcversion_id " .
            "     AND E.platform_id = TPTCV.platform_id " .
            "     AND E.build_id = UA.build_id " .
            " WHERE UA.build_id IN ( " . implode(",",(array)$buildID) . " ) " .
            " AND E.status IS NULL " .       
            " AND type = {$execAssign} " .
            " GROUP BY UA.build_id ";
      
      $rs = $this->db->fetchRowsIntoMap($sql,'build_id');
      
    return $rs;
  }


  /**
   *
   */
  function getUsersByFeatureBuild($featureSet,$buildID,$assignmentType)
  {
    $debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
    $rs = null;
    
    if(is_null($assignmentType) || !is_numeric($assignmentType) )
    {
      throw new Exception(__METHOD__ . ' assignmentType can not be NULL or not numeric ');  
    }
    $sql =  "/* $debugMsg */ ".
            " SELECT UA.user_id,UA.feature_id ".
            " FROM {$this->tables['user_assignments']} UA " .
            " WHERE UA.build_id = " . intval($buildID) . 
            " AND UA.feature_id IN(" . implode(",",(array)$featureSet)  . " )" .       
            " AND type = " . intval($assignmentType);
            
    $rs = $this->db->fetchRowsIntoMap($sql,'feature_id');
    return $rs;
  }
  
}
