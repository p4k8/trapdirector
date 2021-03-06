<?php

namespace Trapdirector;


/**
 * MIB queries and update
 * 
 * @license GPL
 * @author Patrick Proy
 * @package trapdirector
 * @subpackage Processing
 *
 */
class Mib
{
    use \MibDatabase;
    
    /** @var Logging $logging logging class */
    protected $logging;
    /** @var Database $trapsDB Database class */
    protected $trapsDB;
    
    /** @var string $snmptranslate */
    public $snmptranslate;
    /** @var string $snmptranslateDirs */
    public $snmptranslateDirs;
    
    private $dbOidAll; //< All oid in database;
    private $dbOidIndex; //< Index of oid in dbOidAll
    private $objectsAll; //< output lines of snmptranslate list
    private $trapObjectsIndex; //< array of traps objects (as OID)
    
    private $oidDesc=array(); //< $oid,$mib,$name,$type,$textConv,$dispHint,$syntax,$type_enum,$description=NULL

    // Timing vars for update
    private $timing=array();
    
    /**
     * Setup Mib Class
     * @param Logging $logClass : where to log
     * @param Database $dbClass : Database
     */
    function __construct($logClass,$dbClass,$snmptrans,$snmptransdir)
    {
        $this->logging=$logClass;
        $this->trapsDB=$dbClass;
        $this->snmptranslate=$snmptrans;
        $this->snmptranslateDirs=$snmptransdir;

    }
    
    /**
     * @return \Trapdirector\Logging
     */
    public function getLogging()
    {
        return $this->logging;
    }

    /**
     * @return \Trapdirector\Database
     */
    public function getTrapsDB()
    {
        return $this->trapsDB;
    }

/**
 * Get object details & mib , returns snmptranslate output
 * @param string $object : object name
 * @param string $trapmib : mib of trap
 * @return NULL|array : null if not found, or output of snmptranslate
 */
    private function get_object_details($object,$trapmib)
    {
        $match=$snmptrans=array();
        $retVal=0;
        $this->oidDesc['mib']=$trapmib;
        exec($this->snmptranslate . ' -m ALL -M +'.$this->snmptranslateDirs.
            ' -On -Td '.$this->oidDesc['mib'].'::'.$object . ' 2>/dev/null',$snmptrans,$retVal);
        if ($retVal!=0)
        {
            // Maybe not trap mib, search with IR
            exec($this->snmptranslate . ' -m ALL -M +'.$this->snmptranslateDirs.
                ' -IR '.$object . ' 2>/dev/null',$snmptrans,$retVal);
            if ($retVal != 0 || !preg_match('/(.*)::(.*)/',$snmptrans[0],$match))
            { // Not found -> continue with warning
                $this->logging->log('Error finding trap object : '.$trapmib.'::'.$object,2,'');
                return null;
            }
            $this->oidDesc['mib']=$match[1];
            
            // Do the snmptranslate again.
            exec($this->snmptranslate . ' -m ALL -M +'.$this->snmptranslateDirs.
                ' -On -Td '.$this->oidDesc['mib'].'::'.$object,$snmptrans,$retVal);
            if ($retVal!=0) {
                $this->logging->log('Error finding trap object : '.$this->oidDesc['mib'].'::'.$object,2,'');
                return null;
            }
            
        }
        return $snmptrans;
    }

/**
 * Parse snmptranslate output and set  $this->oidDesc with elements 
 * @param array $snmptrans : multi line output of snmptrans
 */
    private function parse_object($snmptrans)
    {
        $tmpdesc=''; // For multiline description
        $indesc=false; // true if currently inside multiline description
        $match=array();
        
        foreach ($snmptrans as $line)
        {
            if ($indesc===true)
            {
                $line=preg_replace('/[\t ]+/',' ',$line);
                if (preg_match('/(.*)"$/', $line,$match))
                {
                    $this->oidDesc['description'] = $tmpdesc . $match[1];
                    $indesc=false;
                }
                $tmpdesc.=$line;
                continue;
            }
            if (preg_match('/^\.[0-9\.]+$/', $line))
            {
                $this->oidDesc['oid']=$line;
                continue;
            }
            if (preg_match('/^[\t ]+SYNTAX[\t ]+([^{]*) \{(.*)\}/',$line,$match))
            {
                $this->oidDesc['syntax']=$match[1];
                $this->oidDesc['type_enum']=$match[2];
                continue;
            }
            if (preg_match('/^[\t ]+SYNTAX[\t ]+(.*)/',$line,$match))
            {
                $this->oidDesc['syntax']=$match[1];
                continue;
            }
            if (preg_match('/^[\t ]+DISPLAY-HINT[\t ]+"(.*)"/',$line,$match))
            {
                $this->oidDesc['dispHint']=$match[1];
                continue;
            }
            if (preg_match('/^[\t ]+DESCRIPTION[\t ]+"(.*)"/',$line,$match))
            {
                $this->oidDesc['description']=$match[1];
                continue;
            }
            if (preg_match('/^[\t ]+DESCRIPTION[\t ]+"(.*)/',$line,$match))
            {
                $tmpdesc=$match[1];
                $indesc=true;
                continue;
            }
            if (preg_match('/^[\t ]+-- TEXTUAL CONVENTION[\t ]+(.*)/',$line,$match))
            {
                $this->oidDesc['textconv']=$match[1];
                continue;
            }
        }
    }

    /**
     * create or update (with check_existing = true) objects of trap
     * @param string $trapOID : trap oid
     * @param string $trapmib : mib of trap
     * @param array $objects : array of objects name (without MIB)
     * @param bool $check_existing : check instead of create
     */
    public function trap_objects($trapOID,$trapmib,$objects,$check_existing)
    {              
        $trapId = $this->dbOidIndex[$trapOID]['id']; // Get id of trap
        
        if ($check_existing === true)
        {
            $dbObjects=$this->cache_db_objects($trapId);
        }
        
        foreach ($objects as $object)
        {
            
            $this->reset_oidDesc();
            
            $snmptrans=$this->get_object_details($object, $trapmib); // Get object mib & details
            if ($snmptrans === null) continue; // object not found
            
            $this->parse_object($snmptrans);

            $this->oidDesc['name'] = $object;
            
            $this->logging->log("Adding object ".$this->oidDesc['name']." : ".$this->oidDesc['oid']." / ".$this->oidDesc['syntax']." / ".$this->oidDesc['type_enum']." / ".$this->oidDesc['dispHint']." / ".$this->oidDesc['textconv'],DEBUG );

            // Update
            $this->update_oid();
            
            if (isset($dbObjects[$this->dbOidIndex[$this->oidDesc['oid']]['id']]))
            {   // if link exists, continue
                $dbObjects[$this->dbOidIndex[$this->oidDesc['oid']]['id']]=2;
                continue;
            }
            if ($check_existing === true)
            {
                // TODO : check link trap - objects exists, mark them.
            }
            // Associate in object table
            $db_conn=$this->trapsDB->db_connect_trap();
            $sql='INSERT INTO '.$this->trapsDB->dbPrefix.'mib_cache_trap_object (trap_id,object_id) '.
                'values (:trap_id, :object_id)';
            $sqlQuery=$db_conn->prepare($sql);
            $sqlParam=array(
                ':trap_id' => $trapId,
                ':object_id' => $this->dbOidIndex[$this->oidDesc['oid']]['id'],
            );
            
            if ($sqlQuery->execute($sqlParam) === false) {
                $this->logging->log('Error adding trap object : ' . $sql . ' / ' . $trapId . '/'. $this->dbOidIndex[$this->oidDesc['oid']]['id'] ,1,'');
            }
        }
        if ($check_existing === true)
        {
            // TODO : remove link trap - objects that wasn't marked.
        }
        
    }

    private function reset_oidDesc()
    {
        $this->oidDesc['oid']=null;
        $this->oidDesc['name']=null;
        $this->oidDesc['type']=null;
        $this->oidDesc['mib']=null;
        $this->oidDesc['textconv']=null;
        $this->oidDesc['dispHint'] =null;
        $this->oidDesc['syntax']=null;
        $this->oidDesc['type_enum']=null;
        $this->oidDesc['description']=null;
    }
    
    /**
     * Fills $this->objectsAll with all mibs from snmptranslate
     * @return integer : number of elements 
     */
    private function load_mibs_snmptranslate()
    {
        $retVal=0;
        // Get all mib objects from all mibs
        $snmpCommand=$this->snmptranslate . ' -m ALL -M +'.$this->snmptranslateDirs.' -On -Tto 2>/dev/null';
        $this->logging->log('Getting all traps : '.$snmpCommand,DEBUG );
        unset($this->objectsAll);
        exec($snmpCommand,$this->objectsAll,$retVal);
        if ($retVal!=0)
        {
            $this->logging->log('error executing snmptranslate',ERROR,'');
        }
        // Count elements to show progress
        $numElements=count($this->objectsAll);
        $this->logging->log('Total snmp objects returned by snmptranslate : '.$numElements,INFO );
        return $numElements;
    }

    /**
     * load all mib objects db in dbOidAll (raw) and index in dbOidIndex
     */
    private function load_mibs_from_db()
    {
        // Get all mibs from databse to have a memory index
        
        $db_conn=$this->trapsDB->db_connect_trap();
        
        $sql='SELECT * from '.$this->trapsDB->dbPrefix.'mib_cache;';
        $this->logging->log('SQL query : '.$sql,DEBUG );
        if (($ret_code=$db_conn->query($sql)) === false) {
            $this->logging->log('No result in query : ' . $sql,ERROR,'');
        }
        $this->dbOidAll=$ret_code->fetchAll();
        $this->dbOidIndex=array();
        // Create the index for db;
        foreach($this->dbOidAll as $key=>$val)
        {
            $this->dbOidIndex[$val['oid']]['key']=$key;
            $this->dbOidIndex[$val['oid']]['id']=$val['id'];
        }
    }

    /**
     * Reset all update timers & count to zero
     */
    private function reset_update_timers()
    {
        $this->timing['base_parse_time']=0;
        $this->timing['base_check_time']=0;
        $this->timing['type0_check_time']=0;
        $this->timing['nottrap_time']=0;
        $this->timing['update_time']=0;
        $this->timing['objects_time']=0;
        $this->timing['base_parse_num']=0;
        $this->timing['base_check_num']=0;
        $this->timing['type0_check_num']=0;
        $this->timing['nottrap_num']=0;
        $this->timing['update_num']=0;
        $this->timing['objects_num']=0;
        $this->timing['num_traps']=0;
    }

    /**
     * Detect if $this->objectsAll[$curElement] is a trap 
     * @param integer $curElement
     * @param bool $onlyTraps : set to false to get all and not only traps.
     * @return boolean : false if it's a trap , true if not
     */
    private function detect_trap($curElement,$onlyTraps)
    {
        // Get oid or pass if not found
        if (!preg_match('/^\.[0-9\.]+$/',$this->objectsAll[$curElement]))
        {
            $this->timing['base_parse_time'] += microtime(true) - $this->timing['base_time'];
            $this->timing['base_parse_num'] ++;
            return true;
        }
        $this->oidDesc['oid']=$this->objectsAll[$curElement];
        
        // get next line
        $curElement++;
        $match=$snmptrans=array();
        if (!preg_match('/ +([^\(]+)\(.+\) type=([0-9]+)( tc=([0-9]+))?( hint=(.+))?/',
            $this->objectsAll[$curElement],$match))
        {
            $this->timing['base_check_time'] += microtime(true) - $this->timing['base_time'];
            $this->timing['base_check_num']++;
            return true;
        }
        
        $this->oidDesc['name']=$match[1]; // Name
        $this->oidDesc['type']=$match[2]; // type (21=trap, 0: may be trap, else : not trap
        
        if ($this->oidDesc['type']==0) // object type=0 : check if v1 trap
        {
            // Check if next is suboid -> in that case is cannot be a trap
            if (preg_match("/^".$this->oidDesc['oid']."/",$this->objectsAll[$curElement+1]))
            {
                $this->timing['type0_check_time'] += microtime(true) - $this->timing['base_time'];
                $this->timing['type0_check_num']++;
                return true;
            }
            unset($snmptrans);
            $retVal=0;
            exec($this->snmptranslate . ' -m ALL -M +'.$this->snmptranslateDirs.
                ' -Td '.$this->oidDesc['oid'] . ' | grep OBJECTS ',$snmptrans,$retVal);
            if ($retVal!=0)
            {
                $this->timing['type0_check_time'] += microtime(true) - $this->timing['base_time'];
                $this->timing['type0_check_num']++;
                return true;
            }
            //echo "\n v1 trap found : $this->oidDesc['oid'] \n";
            // Force as trap.
            $this->oidDesc['type']=21;
        }
        if ($onlyTraps===true && $this->oidDesc['type']!=21) // if only traps and not a trap, continue
        {
            $this->timing['nottrap_time'] += microtime(true) - $this->timing['base_time'];
            $this->timing['nottrap_num']++;
            return true;
        }
        return false;
    }
   
    /**
     * get_trap_mib_description
     * @return array|null : array of snmptranslate output or null on error
    **/
    private function get_trap_mib_description()
    {
        $retVal=0;
        $match=$snmptrans=array();
        exec($this->snmptranslate . ' -m ALL -M +'.$this->snmptranslateDirs.
            ' -Td '.$this->oidDesc['oid'],$snmptrans,$retVal);
        if ($retVal!=0)
        {
            $this->logging->log('error executing snmptranslate',ERROR);
            return $snmptrans;
        }
        
        if (!preg_match('/^(.*)::/',$snmptrans[0],$match))
        {
            $this->logging->log('Error getting mib from trap '.$this->oidDesc['oid'].' : ' . $snmptrans[0],ERROR);
            return $snmptrans;
        }
        $this->oidDesc['mib']=$match[1];
        
        $numLine=1;
        while (isset($snmptrans[$numLine]) && !preg_match('/^[\t ]+DESCRIPTION[\t ]+"(.*)/',$snmptrans[$numLine],$match)) $numLine++;
        if (isset($snmptrans[$numLine]))
        {
            $snmptrans[$numLine] = preg_replace('/^[\t ]+DESCRIPTION[\t ]+"/','',$snmptrans[$numLine]);
            
            while (isset($snmptrans[$numLine]) && !preg_match('/"/',$snmptrans[$numLine]))
            {
                $this->oidDesc['description'].=preg_replace('/[\t ]+/',' ',$snmptrans[$numLine]);
                $numLine++;
            }
            if (isset($snmptrans[$numLine])) {
                $this->oidDesc['description'].=preg_replace('/".*/','',$snmptrans[$numLine]);
                $this->oidDesc['description']=preg_replace('/[\t ]+/',' ',$this->oidDesc['description']);
            }
            
        }
        return $snmptrans;
    }

    /**
     * Get trap objects
     * @param array $snmptrans : output of snmptranslate for TrapModuleConfig
     * @return array|null : array of objects or null if not found
    **/
    private function get_trap_objects($snmptrans)
    {
        $objectName=null;
        $match=array();
        foreach ($snmptrans as $line)
        {
            if (preg_match('/OBJECTS.*\{([^\}]+)\}/',$line,$match))
            {
                $objectName=$match[1];
            }
        }
        if ($objectName == null)
        {
            $this->logging->log('No objects for ' . $this->oidDesc['oid'],DEBUG);
            $this->timing['objects_time'] += microtime(true) - $this->timing['base_time'];
            return null;
        }
        
        $trapObjects=array();
        while (preg_match('/ *([^ ,]+) *,* */',$objectName,$match))
        {
            array_push($trapObjects,$match[1]);
            $objectName=preg_replace('/'.$match[0].'/','',$objectName);
        }
        return $trapObjects;
    }
    
    /**
     * Cache mib in database
     * @param boolean $display_progress : Display progress on standard output
     * @param boolean $check_change : Force check of trap params & objects
     * @param boolean $onlyTraps : only cache traps and objects (true) or all (false)
     * @param string $startOID : only cache under startOID (NOT IMPLEMENTED)
     */
    public function update_mib_database($display_progress=false,$check_change=false,$onlyTraps=true,$startOID='.1')
    {
        // Global Timing
        $timeTaken = microtime(true);
        
        $numElements=$this->load_mibs_snmptranslate(); // Load objectsAll
        
        $this->load_mibs_from_db(); // Load from db dbOidAll & dbOidIndex
        
        $step=$basestep=$numElements/10; // output display of % done
        $num_step=0;
        $timeFiveSec = microtime(true); // Used for display a '.' every <n> seconds
        
        // Create index for trap objects
        $this->trapObjectsIndex=array();
        
        // detailed timing (time_* vars)
        $this->reset_update_timers();
        
        for ($curElement=0;$curElement < $numElements;$curElement++)
        {
            $this->timing['base_time']= microtime(true);
            if ($display_progress)
            {
                if ((microtime(true)-$timeFiveSec) > 2)
                { // echo a . every 2 sec
                    echo '.';
                    $timeFiveSec = microtime(true);
                }
                if ($curElement>$step)
                { // display progress
                    $num_step++;
                    $step+=$basestep;   
                    echo "\n" . ($num_step*10). '% : ';
                }
            }
            
            $this->reset_oidDesc();
            if ($this->detect_trap($curElement,$onlyTraps)===true)
            {
                continue;
            }
            
            $this->timing['num_traps']++;
            
            $this->logging->log('Found trap : '.$this->oidDesc['name'] . ' / OID : '.$this->oidDesc['oid'],INFO );
            if ($display_progress) echo '#'; // echo a # when trap found

            // get trap objects & source MIB
            
            $snmptrans=$this->get_trap_mib_description(); // get MIB & description


            $update=$this->update_oid(); // Do update of trap.
            
            $this->timing['update_time'] += microtime(true) - $this->timing['base_time'];
            $this->timing['update_num']++;
            
            $this->timing['base_time']= microtime(true); // Reset to check object time
            
            if (($update==0) && ($check_change===false))
            { // Trapd didn't change & force check disabled
                $this->timing['objects_time'] += microtime(true) - $this->timing['base_time'];
                if ($display_progress) echo "C";
                continue;
            }
            
            $trapObjects=$this->get_trap_objects($snmptrans); // Get trap objects from snmptranslate output            
            if ($trapObjects == null)
            {
                continue;
            }
           
            $this->trap_objects($this->oidDesc['oid'], $this->oidDesc['mib'], $trapObjects, false);
            
            $this->timing['objects_time'] += microtime(true) - $this->timing['base_time'];
            $this->timing['objects_num']++;
        }
        
        if ($display_progress)
        {
            echo "\nNumber of processed traps :  ". $this->timing['num_traps'] ."\n";
            echo "\nParsing : " . number_format($this->timing['base_parse_time']+$this->timing['base_check_time'],1) ." sec / " . ($this->timing['base_parse_num']+ $this->timing['base_check_num'])  . " occurences\n";
            echo "Detecting traps : " . number_format($this->timing['type0_check_time']+$this->timing['nottrap_time'],1) . " sec / " . ($this->timing['type0_check_num']+$this->timing['nottrap_num']) ." occurences\n";
            echo "Trap processing (".$this->timing['update_num']."): ".number_format($this->timing['update_time'],1)." sec , ";
            echo "Objects processing (".$this->timing['objects_num'].") : ".number_format($this->timing['objects_time'],1)." sec \n";
            
            $timeTaken=microtime(true) - $timeTaken;
            echo "Global time : ".round($timeTaken)." seconds\n";
        }
    }
    
    
}