<?php


//use FontLib\EOT\File;

include (dirname(__DIR__).'/library/Trapdirector/Icinga2Api.php');

use Icinga\Module\Trapdirector\Icinga2API;

define("ERROR", 1);define("WARN", 2);define("INFO", 3);define("DEBUG", 4);

class Trap
{
	// Configuration files a dirs
	protected $icingaweb2_etc; //< Icinga etc path	
	protected $trap_module_config; //< config.ini of module	
	protected $icingaweb2_ressources; //< resources.ini of icingaweb2
	// Options from config.ini 
	protected $snmptranslate='/usr/bin/snmptranslate';
	protected $snmptranslate_dirs='/usr/share/icingaweb2/modules/trapdirector/mibs';
	protected $icinga2cmd='/var/run/icinga2/cmd/icinga2.cmd';
	protected $db_prefix='traps_';

	// API
	protected $api_use=false;
	protected $icinga2api=null;
	protected $api_hostname='';
	protected $api_port='';
	protected $api_username='';
	protected $api_password='';

	//**** Options from config database
	// Logs 
	protected $debug_level=2;  // 0=No output 1=critical 2=warning 3=trace 4=ALL
	protected $alert_output='display'; // alert type : file, syslog, display
	protected $debug_file="/tmp/trapdebug.txt";
	protected $debug_text=array("","Error","Warning","Info","Debug");
	
	//**** End options from database
	
	//protected $debug_file="php://stdout";	
	// Databases
	protected $trapDB=null; //< trap database
	protected $idoDB=null; //< ido database
	protected $trapDBType; //< Type of database for traps (mysql, pgsql)
	protected $idoDBType; //< Type of database for ido (mysql, pgsql)
	
	// Trap received data
	protected $receivingHost;
	public $trap_data=array(); //< Main trap data (oid, source...)
	public $trap_data_ext=array(); //< Additional trap data objects (oid/value).
	public $trap_id=null; //< trap_id after sql insert
	public $trap_action=null; //< trap action for final write
	protected $trap_to_db=true; //< log trap to DB
	
	// Mib update data
	private $dbOidAll; //< All oid in database;
	private $dbOidIndex; //< Index of oid in dbOidAll
	private $objectsAll; //< output lines of snmptranslate list
	private $trapObjectsIndex; //< array of traps objects (as OID)
	
	function __construct($etc_dir='/etc/icingaweb2')
	{
		$this->icingaweb2_etc=$etc_dir;
		$this->trap_module_config=$this->icingaweb2_etc."/modules/trapdirector/config.ini";		
		$this->icingaweb2_ressources=$this->icingaweb2_etc."/resources.ini";
		
		$this->getOptions();

		$this->trap_data=array(
			'source_ip'	=> 'unknown',
			'source_port'	=> 'unknown',
			'destination_ip'	=> 'unknown',
			'destination_port'	=> 'unknown',
			'trap_oid'	=> 'unknown',
		);
	}
	
	/**
	 * Get option from array of ini file, send message if empty
	 * @param string $option_array Array of ini file
	 * @param string $option_category category in ini file
	 * @param string $option_name name of option in category
	 * @param resource $option_var variable to fill if found, left untouched if not found
	 * @param number $log_level default 2 (warning)
	 * @param string $message warning message if not found
	 * @return boolean true if found, or false
	 */
	protected function getOptionIfSet($option_array,$option_category,$option_name, &$option_var, $log_level = 2, $message = null)
	{
	    if (!isset($option_array[$option_category][$option_name]))
	    {
	        if ($message === null)
	        {
	            $message='No ' . $option_name . ' in config file: '. $this->trap_module_config;
	        }
	        $this->trapLog($message,$log_level,'syslog');
	        return false;
	    }
	    else
	    {
	        $option_var=$option_array[$option_category][$option_name];
	        return true;
	    }
	}
	
	/** Get options from ini file and database
	*/
	protected function getOptions()
	{
		$trap_config=parse_ini_file($this->trap_module_config,true);
		if ($trap_config == false) 
		{
			$this->trapLog("Error reading ini file : ".$this->trap_module_config,ERROR,'syslog'); 
		}
		// Snmptranslate binary path
		$this->getOptionIfSet($trap_config,'config','snmptranslate', $this->snmptranslate);

		// mibs path
		$this->getOptionIfSet($trap_config,'config','snmptranslate_dirs', $this->snmptranslate_dirs);

		// icinga2cmd path
		$this->getOptionIfSet($trap_config,'config','icingacmd', $this->icinga2cmd);
		
		// table prefix
		$this->getOptionIfSet($trap_config,'config','database_prefix', $this->db_prefix);

		// API options
		if ($this->getOptionIfSet($trap_config,'config','icingaAPI_host', $this->api_hostname))
		{
		    $this->api_use=true;
		    $this->getOptionIfSet($trap_config,'config','icingaAPI_port', $this->api_port);
		    $this->getOptionIfSet($trap_config,'config','icingaAPI_user', $this->api_username);
		    $this->getOptionIfSet($trap_config,'config','icingaAPI_password', $this->api_password);
		}
				
		/***** Database options :  ***/
		$this->getDBConfigIfSet('log_level',$this->debug_level);
		$this->getDBConfigIfSet('log_destination',$this->alert_output);
		$this->getDBConfigIfSet('log_file',$this->debug_file);
		$this->getAPI();
	}

	protected function getDBConfigIfSet($element,&$variable)
	{
		$value=$this->getDBConfig($element);
		if ($value != 'null') $variable=$value;
	}
	
	/** Get data from db_config
	*	@param $element string name of param
	*	@return $value (or null)
	*/	
	protected function getDBConfig($element)
	{
		$db_conn=$this->db_connect_trap();
		$sql='SELECT value from '.$this->db_prefix.'db_config WHERE ( name=\''.$element.'\' )';
		if (($ret_code=$db_conn->query($sql)) == FALSE) {
			$this->trapLog('No result in query : ' . $sql,WARN,'');
			return null;
		}
		$value=$ret_code->fetch();
		if ($value != null && isset($value['value']))
		{
			return $value['value'];
		}
		return null;
	}
	
	/** Send log. Throws exception on critical error
	*	@param	string $message Message to log
	*	@param	int $level 1=critical 2=warning 3=trace 4=debug
	*	@param  int $destination file/syslog/display
	*	@return void
	**/	
	public function trapLog( $message, $level, $destination ='')
	{	
		if ($this->debug_level >= $level) 
		{
			$message = '['.  date("Y/m/d H:i:s") . '] ' .
				'['. basename(__FILE__) . '] ['.$this->debug_text[$level].']: ' .$message . "\n";
			
			if ( $destination != '' ) $output=$destination;
			else $output=$this->alert_output;
			switch ($output)
			{
				case 'file':
					file_put_contents ($this->debug_file, $message , FILE_APPEND);
					break;
				case 'syslog':
					switch($level)
					{
						case 1 : $prio = LOG_ERR;break;
						case 2 : $prio = LOG_WARNING;break;
						case 3 : $prio = LOG_INFO;break;
						case 4 : $prio = LOG_DEBUG;break;
					}
					syslog($prio,$message);
					break;
				case 'display':
					echo $message;
					break;
				default : // nothing we can do at this point
					throw new Exception($message);
			}
		}
		if ($level == 1)
		{
			throw new Exception($message);
		}
	}
	
	public function setLogging($debug_lvl,$output_type,$output_option=null)
	{
		$this->debug_level=$debug_lvl;
		switch ($output_type)
		{
			case 'file':
			    if ($output_option == null) throw new Exception("File logging without file !");
				$this->debug_file=$output_option;
				$this->alert_output='file';
				break;
			case 'syslog':
				$this->alert_output='syslog';
				break;
			case 'display':
				$this->alert_output='display';
				break;
			default : // syslog should always work....
				$this->trapLog("Error in log output : ".$output_type,ERROR,'syslog');
		}
	}
	
	protected function getAPI()
	{
	    if ($this->icinga2api == null)
	    {
	        $this->icinga2api = new Icinga2API($this->api_hostname,$this->api_port);
	    }
	    return $this->icinga2api;
	}

	/** Connects to trapdb 
	*	@return PDO connection
	*/
	public function db_connect_trap() 
	{
		if ($this->trapDB != null) {
		    // Check if connection is still alive
		    try {
		        $this->trapDB->query('select 1')->fetchColumn();
		        return $this->trapDB;
		    } catch (Exception $e) {
		        // select 1 failed, try to reconnect.
		        $this->trapDB=null;
				$this->trapDB=$this->db_connect('traps');
		        $this->trapLog('Database connection lost, reconnecting',WARN,'');
				return $this->trapDB;
		    }
		     
		}
		$this->trapDB=$this->db_connect('traps');
		return $this->trapDB;
	}
	

	/** Connects to idodb 
	*	@return PDO connection
	*/
	public function db_connect_ido() 
	{
		if ($this->idoDB != null) { return $this->idoDB; }
		$this->idoDB=$this->db_connect('ido');
		return $this->idoDB;
	}	
	
	/** connects to database named by parameter
	*	@param string : 'traps' for traps database, 'ido' for ido database
	*	@return PDO connection
	**/
	protected function db_connect($database) {
		$confarray=$this->get_database($database);
		//	$dsn = 'mysql:dbname=traps;host=127.0.0.1';
		$dsn= $confarray[0].':dbname='.$confarray[2].';host='.$confarray[1];
		$user = $confarray[3];
		$password = $confarray[4];
		$this->trapLog('DSN : '.$dsn,3);
		try {
			$dbh = new PDO($dsn, $user, $password);
		} catch (PDOException $e) {
			$this->trapLog('Connection failed : ' . $e->getMessage(),ERROR,'');
		}
		return $dbh;
	}

	/** Get database connexion options
	*	@param string database : 'traps' for traps database, 'ido' for ido database
	*	@return array( DB type (mysql, pgsql.) , db_host, database name , db_user, db_pass)
	**/
	protected function get_database($database) {

		$trap_config=parse_ini_file($this->trap_module_config,true);
		if ($trap_config == false) 
		{
			$this->trapLog("Error reading ini file : ".$this->trap_module_config,ERROR,''); 
		}
		if ($database == 'traps')
		{
			if (!isset($trap_config['config']['database'])) 
			{
				$this->trapLog("No Config/database in config file: ".$this->trap_module_config,ERROR,''); 
			}
			$db_name=$trap_config['config']['database'];
		} 
		else if ($database == 'ido')
		{
			if (!isset($trap_config['config']['IDOdatabase'])) 
			{
				$this->trapLog("No Config/IDOdatabase in config file: ".$this->trap_module_config,ERROR,''); 
			}
			$db_name=$trap_config['config']['IDOdatabase'];		
		}
		else
		{
			$this->trapLog("Unknown database type : ".$database,ERROR,''); 		
		}	
		$this->trapLog("Found database in config file: ".$db_name,3,''); 
		$db_config=parse_ini_file($this->icingaweb2_ressources,true);
		if ($db_config == false) 
		{
			$this->trapLog("Error reading ini file : ".$this->icingaweb2_ressources,ERROR,''); 
		}
		if (!isset($db_config[$db_name])) 
		{
			$this->trapLog("No Config/database in config file: ".$this->icingaweb2_ressources,ERROR,''); 
		}
		$db_type=$db_config[$db_name]['db'];
		$db_host=$db_config[$db_name]['host'];
		$db_sql_name=$db_config[$db_name]['dbname'];
		$db_user=$db_config[$db_name]['username'];
		$db_pass=$db_config[$db_name]['password'];
		if ($database == 'traps') $this->trapDBType = $db_type;
		if ($database == 'ido') $this->idoDBType = $db_type;
		
		$this->trapLog( "DB selected : $db_type $db_host $db_sql_name $db_user",3,''); 
		return array($db_type,$db_host,$db_sql_name,$db_user,$db_pass);
	}	
	
	/** read data from stream
	*	@param $stream string input stream, defaults to "php://stdin"
	*	@return array trap data
	*/
	public function read_trap($stream='php://stdin')
	{
		//Read data from snmptrapd from stdin
		$input_stream=fopen($stream, 'r');

		if ($input_stream==FALSE)
		{
		    $this->writeTrapErrorToDB("Error reading trap (code 1/Stdin)");
			$this->trapLog("Error reading stdin !",ERROR,''); 
		}

		// line 1 : host
		$this->receivingHost=chop(fgets($input_stream));
		if ($this->receivingHost === false)
		{
		    $this->writeTrapErrorToDB("Error reading trap (code 1/Line Host)");
			$this->trapLog("Error reading Host !",ERROR,''); 
		}
		// line 2 IP:port=>IP:port
		$IP=chop(fgets($input_stream));
		if ($IP === false)
		{
		    $this->writeTrapErrorToDB("Error reading trap (code 1/Line IP)");
			$this->trapLog("Error reading IP !",ERROR,''); 
		}
		$matches=array();
		$ret_code=preg_match('/.DP: \[(.*)\]:(.*)->\[(.*)\]:(.*)/',$IP,$matches);
		if ($ret_code===0 || $ret_code==false) 
		{
		    $this->writeTrapErrorToDB("Error parsing trap (code 2/IP)");
			$this->trapLog('Error parsing IP : '.$IP,ERROR,'');
		} 
		else 
		{		
			$this->trap_data['source_ip']=$matches[1];
			$this->trap_data['destination_ip']=$matches[3];
			$this->trap_data['source_port']=$matches[2];
			$this->trap_data['destination_port']=$matches[4];
		}

		while (($vars=chop(fgets($input_stream))) !=false)
		{
			$ret_code=preg_match('/^([^ ]+) (.*)$/',$vars,$matches);
			if ($ret_code===0 || $ret_code===false) 
			{
				$this->trapLog('No match on trap data : '.$vars,WARN,'');
			}
			else 
			{
			    if (($matches[1]=='.1.3.6.1.6.3.1.1.4.1.0') || ($matches[1]=='.1.3.6.1.6.3.1.1.4.1'))
				{
					$this->trap_data['trap_oid']=$matches[2];				
				}
				else
				{
					$object= new stdClass;
					$object->oid =$matches[1];
					$object->value = $matches[2];
					array_push($this->trap_data_ext,$object);
				}
			}
		}

		if ($this->trap_data['trap_oid']=='unknown') 
		{
		    $this->writeTrapErrorToDB("No trap oid found : check snmptrapd configuration (code 3/OID)",$this->trap_data['source_ip']);
			$this->trapLog('no trap oid found',ERROR,'');
		} 

		// Translate oids.
		
		$retArray=$this->translateOID($this->trap_data['trap_oid']);
		if ($retArray != null)
		{
			$this->trap_data['trap_name']=$retArray['trap_name'];
			$this->trap_data['trap_name_mib']=$retArray['trap_name_mib'];
		}
		foreach ($this->trap_data_ext as $key => $val)
		{
			$retArray=$this->translateOID($val->oid);
			if ($retArray != null)
			{
				$this->trap_data_ext[$key]->oid_name=$retArray['trap_name'];
				$this->trap_data_ext[$key]->oid_name_mib=$retArray['trap_name_mib'];
			}			
		}
		

		$this->trap_data['status']= 'waiting';
		
		return $this->trap_data;
	}

	/** Translate oid into array(MIB,Name)
	* @param $oid string oid to translate
	* @return mixed : null if not found or array(MIB,Name)
	*/
	public function translateOID($oid)
	{
		// try from database
		$db_conn=$this->db_connect_trap();
		
		$sql='SELECT mib,name from '.$this->db_prefix.'mib_cache WHERE oid=\''.$oid.'\';';
		$this->trapLog('SQL query : '.$sql,4,'');
		if (($ret_code=$db_conn->query($sql)) == FALSE) {
			$this->trapLog('No result in query : ' . $sql,ERROR,'');
		}
		$name=$ret_code->fetch();
		if ($name['name'] != null)
		{
			return array('trap_name_mib'=>$name['mib'],'trap_name'=>$name['name']);
		}
		
		// Also check if it is an instance of OID
		$oid_instance=preg_replace('/\.[0-9]+$/','',$oid);
		
		$sql='SELECT mib,name from '.$this->db_prefix.'mib_cache WHERE oid=\''.$oid_instance.'\';';
		$this->trapLog('SQL query : '.$sql,4,'');
		if (($ret_code=$db_conn->query($sql)) == FALSE) {
			$this->trapLog('No result in query : ' . $sql,ERROR,'');
		}
		$name=$ret_code->fetch();
		if ($name['name'] != null)
		{
			return array('trap_name_mib'=>$name['mib'],'trap_name'=>$name['name']);
		}
		
		// Try to get oid name from snmptranslate
		$translate=exec($this->snmptranslate . ' -m ALL -M +'.$this->snmptranslate_dirs.
		    ' '.$oid);
		$matches=array();
		$ret_code=preg_match('/(.*)::(.*)/',$translate,$matches);
		if ($ret_code===0 || $ret_code===FALSE) {
			return NULL;
		} else {
			$this->trapLog('Found name with snmptrapd and not in DB for oid='.$oid,INFO,'');
			return array('trap_name_mib'=>$matches[1],'trap_name'=>$matches[2]);
		}	
	}
	
	/** Erase old trap records 
	*	@param $days : erase traps when more than $days old
	*	@return : number of lines deleted
	**/
	public function eraseOldTraps($days=0)
	{
		if ($days==0)
		{
			if (($days=$this->getDBConfig('db_remove_days')) == null)
			{
				$this->trapLog('No days specified & no db value : no tap erase' ,WARN,'');
				return;
			}
		}
		$db_conn=$this->db_connect_trap();
		$daysago = strtotime("-".$days." day");
		$sql= 'delete from '.$this->db_prefix.'received where date_received < \''.date("Y-m-d H:i:s",$daysago).'\';';
		if ($db_conn->query($sql) == FALSE) {
			$this->trapLog('Error erasing traps : '.$sql,ERROR,'');
		}
		$this->trapLog('Erased traps older than '.$days.' day(s) : '.$sql,3);
	}

	/** Write error to received trap database
	 */
	public function writeTrapErrorToDB($message,$sourceIP=null,$trapoid=null)
	{
	    
	    $db_conn=$this->db_connect_trap();
	    
	    $insert_col='';
	    $insert_val='';
	    // add date time
	    $insert_col ='date_received,status';
	    $insert_val = "'" . date("Y-m-d H:i:s")."','error'";
        
	    if ($sourceIP !=null)
	    {
	        $insert_col .=',source_ip';
	        $insert_val .=",'". $sourceIP ."'";
	    }
	    if ($trapoid !=null)
	    {
	        $insert_col .=',trap_oid';
	        $insert_val .=",'". $trapoid ."'";
	    }
	    $insert_col .=',status_detail';
	    $insert_val .=",'". $message ."'";
	    
	    $sql= 'INSERT INTO '.$this->db_prefix.'received (' . $insert_col . ') VALUES ('.$insert_val.')';
	    
	    switch ($this->trapDBType)
	    {
	        case 'pgsql':
	            $sql .= ' RETURNING id;';
	            $this->trapLog('sql : '.$sql,3,'');
	            if (($ret_code=$db_conn->query($sql)) == FALSE) {
	                $this->trapLog('Error SQL insert : '.$sql,1,'');
	            }
	            $this->trapLog('SQL insertion OK',3,'');
	            // Get last id to insert oid/values in secondary table
	            if (($inserted_id_ret=$ret_code->fetch(PDO::FETCH_ASSOC)) == FALSE) {
	                
	                $this->trapLog('Erreur recuperation id',1,'');
	            }
	            if (! isset($inserted_id_ret['id'])) {
	                $this->trapLog('Error getting id',1,'');
	            }
	            $this->trap_id=$inserted_id_ret['id'];
	            break;
	        case 'mysql':
	            $sql .= ';';
	            $this->trapLog('sql : '.$sql,3,'');
	            if (($ret_code=$db_conn->query($sql)) == FALSE) {
	                $this->trapLog('Error SQL insert : '.$sql,1,'');
	            }
	            $this->trapLog('SQL insertion OK',3,'');
	            // Get last id to insert oid/values in secondary table
	            $sql='SELECT LAST_INSERT_ID();';
	            if (($ret_code=$db_conn->query($sql)) == FALSE) {
	                $this->trapLog('Erreur recuperation id',1,'');
	            }
	            
	            $inserted_id=$ret_code->fetch(PDO::FETCH_ASSOC)['LAST_INSERT_ID()'];
	            if ($inserted_id==false) throw new Exception("Weird SQL error : last_insert_id returned false : open issue");
	            $this->trap_id=$inserted_id;
	            break;
	        default:
	            $this->trapLog('Error SQL type  : '.$this->trapDBType,1,'');
	    }
	    
	    $this->trapLog('id found: '. $this->trap_id,3,'');    
	}
	
	/** Write trap data to trap database
	*/
	public function writeTrapToDB()
	{
		
		// If action is ignore -> don't send t DB
		if ($this->trap_to_db == false) return;
		
		
		$db_conn=$this->db_connect_trap();
		
		$insert_col='';
		$insert_val='';
		// add date time
		$this->trap_data['date_received'] = date("Y-m-d H:i:s");

		$firstcol=1;
		foreach ($this->trap_data as $col => $val)
		{
			if ($firstcol==0) 
			{
				$insert_col .=',';
				$insert_val .=',';
			}
			$insert_col .= $col ;
			$insert_val .= ($val==null)? 'NULL' : $db_conn->quote($val);
			$firstcol=0;
		}
		
		$sql= 'INSERT INTO '.$this->db_prefix.'received (' . $insert_col . ') VALUES ('.$insert_val.')';
		switch ($this->trapDBType)
		{
			case 'pgsql': 
				$sql .= ' RETURNING id;';
				$this->trapLog('sql : '.$sql,3,'');
				if (($ret_code=$db_conn->query($sql)) == FALSE) {
					$this->trapLog('Error SQL insert : '.$sql,ERROR,'');
				}
				$this->trapLog('SQL insertion OK',3,'');
				// Get last id to insert oid/values in secondary table
				if (($inserted_id_ret=$ret_code->fetch(PDO::FETCH_ASSOC)) == FALSE) {
														   
					$this->trapLog('Erreur recuperation id',ERROR,'');
				}
				if (! isset($inserted_id_ret['id'])) {
					$this->trapLog('Error getting id',ERROR,'');
				}
				$this->trap_id=$inserted_id_ret['id'];
			break;
			case 'mysql': 
				$sql .= ';';
				$this->trapLog('sql : '.$sql,3,'');
				if (($ret_code=$db_conn->query($sql)) == FALSE) {
					$this->trapLog('Error SQL insert : '.$sql,ERROR,'');
				}
				$this->trapLog('SQL insertion OK',3,'');
				// Get last id to insert oid/values in secondary table
				$sql='SELECT LAST_INSERT_ID();';
				if (($ret_code=$db_conn->query($sql)) == FALSE) {
					$this->trapLog('Erreur recuperation id',ERROR,'');
				}

				$inserted_id=$ret_code->fetch(PDO::FETCH_ASSOC)['LAST_INSERT_ID()'];
				if ($inserted_id==false) throw new Exception("Weird SQL error : last_insert_id returned false : open issue");
				$this->trap_id=$inserted_id;
			break;
			default: 
				$this->trapLog('Error SQL type  : '.$this->trapDBType,ERROR,'');
		}
		$this->trapLog('id found: '.$this->trap_id,3,'');
		
		// Fill trap extended data table
		foreach ($this->trap_data_ext as $value) {			
			// TODO : detect if trap value is encoded and decode it to UTF-8 for database
			$firstcol=1;
			$value->trap_id = $this->trap_id;
			$insert_col='';
			$insert_val='';
			foreach ($value as $col => $val)
			{
				if ($firstcol==0) 
				{
					$insert_col .=',';
					$insert_val .=',';
				}
				$insert_col .= $col;
				$insert_val .= ($val==null)? 'NULL' : $db_conn->quote($val);
				$firstcol=0;
			}

			$sql= 'INSERT INTO '.$this->db_prefix.'received_data (' . $insert_col . ') VALUES ('.$insert_val.');';			

			if (($ret_code=$db_conn->query($sql)) == FALSE) {
				$this->trapLog('Erreur insertion data : ' . $sql,WARN,'');
			}	
		}	
	}

	/** Get rules from rule database with ip and oid
	*	@param $ip string ipv4 or ipv6
	*	@param $oid string oid in numeric
	*	@return mixed : PDO object or false
	*/	
	protected function getRules($ip,$oid)
	{
		$db_conn=$this->db_connect_trap();
		// fetch rules based on IP in rule and OID
		$sql='SELECT * from '.$this->db_prefix.'rules WHERE trap_oid=\''.$oid.'\' ';
		$this->trapLog('SQL query : '.$sql,4,'');
		if (($ret_code=$db_conn->query($sql)) == FALSE) {
			$this->trapLog('No result in query : ' . $sql,WARN,'');
			return false;
		}
		$rules_all=$ret_code->fetchAll();
		//echo "rule all :\n";print_r($rules_all);echo "\n";
		$rules_ret=array();
		$rule_ret_key=0;
		foreach ($rules_all as $key => $rule)
		{
			if ($rule['ip4']==$ip || $rule['ip6']==$ip)
			{
				$rules_ret[$rule_ret_key]=$rules_all[$key];
				//TODO : get host name by API (and check if correct in rule).
				$rule_ret_key++;
				continue;
			}
			// TODO : get hosts IP by API
			if (isset($rule['host_group_name']) && $rule['host_group_name']!=null)
			{ // get ips of group members by oid
				$db_conn2=$this->db_connect_ido();
				$sql="SELECT m.host_object_id, a.address as ip4, a.address6 as ip6, b.name1 as host_name
						FROM icinga_objects as o
						LEFT JOIN icinga_hostgroups as h ON o.object_id=h.hostgroup_object_id
						LEFT JOIN icinga_hostgroup_members as m ON h.hostgroup_id=m.hostgroup_id
						LEFT JOIN icinga_hosts as a ON a.host_object_id = m.host_object_id
						LEFT JOIN icinga_objects as b ON b.object_id = a.host_object_id
						WHERE o.name1='".$rule['host_group_name']."';";
				if (($ret_code2=$db_conn2->query($sql)) == FALSE) {
					$this->trapLog('No result in query : ' . $sql,WARN,'');
					continue;
				}
				$grouphosts=$ret_code2->fetchAll();
				//echo "rule grp :\n";print_r($grouphosts);echo "\n";
				foreach ( $grouphosts as $host)
				{
					//echo $host['ip4']."\n";
					if ($host['ip4']==$ip || $host['ip6']==$ip)
					{
						//echo "Rule added \n";
						$rules_ret[$rule_ret_key]=$rules_all[$key];
						$rules_ret[$rule_ret_key]['host_name']=$host['host_name'];
						$rule_ret_key++;
					}	
				}
			}
		}
		//echo "rule rest :\n";print_r($rules_ret);echo "\n";exit(0);
		return $rules_ret;
	}

	/** Add rule match to rule
	*	@param id int : rule id
	*   @param set int : value to set
	*/
	protected function add_rule_match($id, $set)
	{
		$db_conn=$this->db_connect_trap();
		$sql="UPDATE ".$this->db_prefix."rules SET num_match = '".$set."' WHERE (id = '".$id."');";
		if ($db_conn->query($sql) == FALSE) {
			$this->trapLog('Error in update query : ' . $sql,WARN,'');
		}
	}
	
	/** Send SERVICE_CHECK_RESULT with icinga2cmd or API
	 * 
	 * @param string $host
	 * @param string $service
	 * @param number $state numerical staus 
	 * @param string $display
	 * @returnn bool true is service check was sent without error
	*/
	public function serviceCheckResult($host,$service,$state,$display)
	{
	    if ($this->api_use == false)
	    {
    		$send = '[' . date('U') .'] PROCESS_SERVICE_CHECK_RESULT;' .
    			$host.';' .$service .';' . $state . ';'.$display;
    		$this->trapLog( $send." : to : " .$this->icinga2cmd,3,'');
    		
    		// TODO : file_put_contents & fopen (,'w' or 'a') does not work. See why. Or not as using API will be by default....
    		exec('echo "'.$send.'" > ' .$this->icinga2cmd);
    		return true;
	    }
	    else
	    {
	        $api = $this->getAPI();
	        $api->setCredentials($this->api_username, $this->api_password);
	        list($retcode,$retmessage)=$api->serviceCheckResult($host,$service,$state,$display);
	        if ($retcode == false)
	        {
	            $this->trapLog( "Error sending result : " .$retmessage,WARN,'');
	            return false;
	        }
	        else 
	        {
	            $this->trapLog( "Sent result : " .$retmessage,3,'');
	            return true;
	        }
	    }
	}
	
	public function getHostByIP($ip)
	{
	    $api = $this->getAPI();
	    $api->setCredentials($this->api_username, $this->api_password);
	    return $api->getHostByIP($ip);
	}
	
	/** Resolve display. 
	*	Changes OID(<oid>) to value if found or text "<not in trap>"
	*	@param $display string
	*	@return string display
	*/
	protected function applyDisplay($display)
	{
	    $matches=array();
	    while (preg_match('/_OID\(([0-9\.]+)\)/',$display,$matches) == 1)
		{
			$oid=$matches[1];
			$found=0;
			foreach($this->trap_data_ext as $val)
			{
				if ($oid == $val->oid)
				{
					$val->value=preg_replace('/"/','',$val->value);
					$rep=0;
					$display=preg_replace('/_OID\('.$oid.'\)/',$val->value,$display,-1,$rep);
					if ($rep==0)
					{
						$this->trapLog("Error in display",WARN,'');
						return $display;
					}
					$found=1;
					break;
				}
			}
			if ($found==0)
			{
				$display=preg_replace('/_OID\('.$oid.'\)/','<not in trap>',$display,-1,$rep);
				if ($rep==0)
				{
					$this->trapLog("Error in display",WARN,'');
					return $display;
				}				
			}
		}
		return $display;
	}

	
	/***************** Eval & tokenizer functions ****************/
	protected function eval_getElement($rule,&$item)
	{
		while ($rule[$item]==' ') $item++;
		if (preg_match('/[0-9\.]/',$rule[$item]))
		{ // number
	
			$item2=$item+1; 
			while (($item2!=strlen($rule)) && (preg_match('/[0-9\.]/',$rule[$item2]))) { $item2++ ;}
			$val=substr($rule,$item,$item2-$item);
			$item=$item2;
			//echo "number ".$val."\n";
			return array(0,$val);
		}
		if ($rule[$item] == '"')
		{ // string
			$item++;
			$item2=$this->eval_getNext($rule,$item,'"');
			$val=substr($rule,$item,$item2-$item-1);
			$item=$item2;
			//echo "string : ".$val."\n";
			return array(1,$val);
		}
		
		if ($rule[$item] == '(')
		{ // grouping
		    $item++;
			$start=$item;
			$parenthesis_count=0; 
			while (($item < strlen($rule)) // Not end of string AND
			      && ( ($rule[$item] != ')' ) || $parenthesis_count > 0) ) // Closing ')' or embeded ()
			{ 
				if ($rule[$item] == '"' )
				{ // pass through string
					$item++;
					$item=$this->eval_getNext($rule,$item,'"');
				} 
				else{
				    if ($rule[$item] == '(')
				    {
				        $parenthesis_count++;
				    }
				    if ($rule[$item] == ')')
				    {
				        $parenthesis_count--;
				    }
					$item++;
				}
			}
			
			if ($item==strlen($rule)) {throw new Exception("no closing () in ".$rule ." at " .$item);}
			$val=substr($rule,$start,$item-$start);
			$item++;
			$start=0;
			//echo "group : ".$val."\n";
			// returns evaluation of group as type 2 (boolean)
			return array(2,$this->evaluation($val,$start));		
		}
		throw new Exception("number/string not found in ".$rule ." at " .$item . ' : ' .$rule[$item]);
		
	}
	
	protected function eval_getNext($rule,$item,$tok)
	{
		while (($rule[$item] != $tok ) && ($item < strlen($rule))) { $item++;}
		if ($item==strlen($rule)) throw new Exception("closing '".$tok."' not found in ".$rule ." at " .$item);
		return $item+1;
	}
	
	protected function eval_getOper($rule,&$item)
	{
		while ($rule[$item]==' ') $item++;
		switch ($rule[$item])
		{
			case '<':
				if ($rule[$item+1]=='=') { $item+=2; return array(0,"<=");}
				$item++; return array(0,"<");
			case '>':
				if ($rule[$item+1]=='=') { $item+=2; return array(0,">=");}
				$item++; return array(0,">");
			case '=':
				$item++; return array(0,"=");	
			case '!':
				if ($rule[$item+1]=='=') { $item+=2; return array(0,"!=");}
				throw new Exception("Erreur in expr - incorrect operator '!'  found in ".$rule ." at " .$item);
			case '~':
				$item++; return array(0,"~");	
			case '|':
				$item++; return array(1,"|");	
			case '&':
				$item++; return array(1,"&");
			default	:
				throw new Exception("Erreur in expr - operator not found in ".$rule ." at " .$item);
		}
	}
	
	/** Evaluation : makes token and evaluate. 
	*	Public function for expressions testing
	*	accepts : < > = <= >= !=  (typec = 0)
	*	operators : & | (typec=1)
	*	with : integers/float  (type 0) or strings "" (type 1) or results (type 2)
	*   comparison int vs strings will return null (error)
	*	return : bool or null on error
	*/
	public function evaluation($rule,&$item)
	{
	    //echo "Evaluation of ".substr($rule,$item)."\n";
		if ( $rule[$item] == '!') // If '!' found, negate next expression.
		{
		    $negate=true;
		    $item++;
		}
		else
		{
		    $negate=false;
		}
		// First element : number, string or ()
		list($type1,$val1) = $this->eval_getElement($rule,$item);
		//echo "Elmt1: ".$val1."/".$type1." : ".substr($rule,$item)."\n";
		
		if ($item==strlen($rule)) // If only element, return value, but only boolean
		{
		  if ($type1 != 2) throw new Exception("Cannot use num/string as boolean : ".$rule);
		  if ($negate == true) $val1= ! $val1;
		  return $val1;
		}  
		
		// Second element : operator
		list($typec,$comp) = $this->eval_getOper($rule,$item);
		//echo "Comp : ".$comp." : ".substr($rule,$item)."\n";
        
		// Third element : number, string or ()
		if ( $rule[$item] == '!') // starts with a ! so evaluate whats next
		{
		    $item++;
		    if ($typec != 1) throw new Exception("Mixing boolean and comparison : ".$rule);
		    $val2= ! $this->evaluation($rule,$item);
		    $type2=2; // result is a boolean 
		}
		else 
		{
		    list($type2,$val2) = $this->eval_getElement($rule,$item);
		}
		//echo "Elmt2: ".$val2."/".$type2." : ".substr($rule,$item)."\n";
		
		if ($type1!=$type2)  // cannot compare different types
		{ 
		    throw new Exception("Cannot compare string & number : ".$rule);
		}
		if ($typec==1 && $type1 !=2) // cannot use & or | with string/number
		{
		    throw new Exception("Cannot use boolean operators with string & number : ".$rule);
		}
		
		switch ($comp){
			case '<':	$retVal= ($val1 < $val2); break;
			case '<=':	$retVal= ($val1 <= $val2); break;
			case '>':	$retVal= ($val1 > $val2); break;
			case '>=':	$retVal= ($val1 >= $val2); break;
			case '=':	$retVal= ($val1 == $val2); break;
			case '!=':	$retVal= ($val1 != $val2); break;
			case '~':	$retVal= (preg_match('/'.preg_replace('/"/','',$val2).'/',$val1)); break;
			case '|':	$retVal= ($val1 || $val2); break;
			case '&':	$retVal= ($val1 && $val2); break;
			default:  throw new Exception("Error in expression - unknown comp : ".$comp);
		}
		if ($negate == true) $retVal = ! $retVal; // Inverse result if negate before expression
		
		if ($item==strlen($rule)) return $retVal; // End of string : return evaluation
		// check for logical operator :
		switch ($rule[$item])
		{
			case '|':	$item++; return ($retVal || $this->evaluation($rule,$item) ); break;
			case '&':	$item++; return ($retVal && $this->evaluation($rule,$item) ); break;
			
			default:  throw new Exception("Erreur in expr - garbadge at end of expression : ".$rule[$item]);
		}
	}
	// Remove all whitespaces (when not quoted)
	public function eval_cleanup($rule)
	{
		$item=0;
		$rule2='';
		while ($item < strlen($rule))
		{
			if ($rule[$item]==' ') { $item++; continue; }
			if ($rule[$item]=='"')
			{
				$rule2.=$rule[$item];
				$item++;
				while (($rule[$item]!='"') && ($item < strlen($rule)))
				{
					$rule2.=$rule[$item];
					$item++;
				}
				if ($item == strlen ($rule)) throw new Exception("closing '\"' not found in ".$rule ." at " .$item);
				$rule2.=$rule[$item];
				$item++;
				continue;
			}
			
			$rule2.=$rule[$item];
			$item++;		
		}
		
		return $rule2;		
	}		
	
	/** Evaluation rule (uses eval_* functions recursively)
	*	@param $rule string rule ( _OID(.1.3.6.1.4.1.8072.2.3.2.1)=_OID(.1.3.6.1.2.1.1.3.0) )
	*	@return : true : rule match, false : rule don't match , throw exception on error.
	*/
	
	protected function eval_rule($rule)
	{
		if ($rule==null || $rule == '') // Empty rule is always true
		{
			return true;
		}
		$matches=array();
		while (preg_match('/_OID\(([0-9\.\*]+)\)/',$rule,$matches) == 1)
		{
			$oid=$matches[1];
			$found=0;
			// ** replaced by .*
			$oidR=preg_replace('/\*\*/', '.*', $oid);
			// * replaced by [^.]*  
			$oidR=preg_replace('/\*/', '[0-9]+', $oidR);
			
			// replace * with \* in oid for preg_replace
			$oid=preg_replace('/\*/', '\*', $oid);
			
			$this->trapLog('OID in rule : '.$oid.' / '.$oidR,4,'');
			
			foreach($this->trap_data_ext as $val)
			{
				if (preg_match("/^$oidR$/",$val->oid) == 1)
				{
					if (!preg_match('/^[0-9]*\.?[0-9]+$/',$val->value))
					{ // If not a number, change " to ' and put " around it
						$val->value=preg_replace('/"/',"'",$val->value);
						$val->value='"'.$val->value.'"';
					}
					$rep=0;
					$rule=preg_replace('/_OID\('.$oid.'\)/',$val->value,$rule,-1,$rep);
					if ($rep==0)
					{
						$this->trapLog("Error in rule_eval",WARN,'');
						return false;
					}
					$found=1;
					break;
				}
			}
			if ($found==0)
			{	// OID not found : throw error
			    throw new Exception('OID '.$oid.' not found in trap');
			}
		}
		$item=0;
		$rule=$this->eval_cleanup($rule);
		$this->trapLog('Rule after clenup: '.$rule,3,'');
		
		return  $this->evaluation($rule,$item);
	}
	
	/** Match rules for current trap and do action
	*/
	public function applyRules()
	{
		$rules = $this->getRules($this->trap_data['source_ip'],$this->trap_data['trap_oid']);
		
		if ($rules==FALSE || count($rules)==0)
		{
			$this->trapLog('No rules found for this trap',3,'');
			$this->trap_data['status']='unknown';
			$this->trap_to_db=true;
			return;
		}
		//print_r($rules);
		// Evaluate all rules in sequence
		$this->trap_action=null;
		foreach ($rules as $rule)
		{
			
			$host_name=$rule['host_name'];
			$service_name=$rule['service_name'];
			
			$display=$this->applyDisplay($rule['display']);
			$this->trap_action = ($this->trap_action==null)? '' : $this->trap_action . ', ';
			try
			{
				$this->trapLog('Rule to eval : '.$rule['rule'],3,'');
				$evalr=$this->eval_rule($rule['rule']);
				
				if ($evalr == true)
				{
					//$this->trapLog('rules OOK: '.print_r($rule),3,'');
					$action=$rule['action_match'];
					$this->trapLog('action OK : '.$action,3,'');
					if ($action >= 0)
					{
						if ($this->serviceCheckResult($host_name,$service_name,$action,$display) == false)
						{
						    $this->trap_action.='Error sending status : check cmd/API';
						}
						else
						{
						    $this->add_rule_match($rule['id'],$rule['num_match']+1);
						    $this->trap_action.='Status '.$action.' to '.$host_name.'/'.$service_name;
						}
					}
					else
					{
						$this->add_rule_match($rule['id'],$rule['num_match']+1);
					}
					$this->trap_to_db=($action==-2)?false:true;
				}
				else
				{
					//$this->trapLog('rules KOO : '.print_r($rule),3,'');
					
					$action=$rule['action_nomatch'];
					$this->trapLog('action NOK : '.$action,3,'');
					if ($action >= 0)
					{
					    if ($this->serviceCheckResult($host_name,$service_name,$action,$display)==false)
					    {
					        $this->trap_action.='Error sending status : check cmd/API';
					    }
					    else
					    {
    						$this->add_rule_match($rule['id'],$rule['num_match']+1);
    						$this->trap_action.='Status '.$action.' to '.$host_name.'/'.$service_name;
					    }
					}
					else
					{
						$this->add_rule_match($rule['id'],$rule['num_match']+1);
					}
					$this->trap_to_db=($action==-2)?false:true;					
				}
				// Put name in source_name
				if (!isset($this->trap_data['source_name']))
				{
					$this->trap_data['source_name']=$rule['host_name'];
				}
				else
				{
					if (!preg_match('/'.$rule['host_name'].'/',$this->trap_data['source_name']))
					{ // only add if not present
						$this->trap_data['source_name'].=','.$rule['host_name'];
					}
				}
			}
			catch (Exception $e) 
			{ 
			    $this->trapLog('Error in rule eval : '.$e->getMessage(),WARN,'');
			    $this->trap_action.=' ERR : '.$e->getMessage();
			    $this->trap_data['status']='error';
			}
			
		}
		if ($this->trap_data['status']=='error')
		{
		  $this->trap_to_db=true; // Always put errors in DB for the use can see
		}
		else
		{
		  $this->trap_data['status']='done';
		}
	}

	/** Add Time a action to rule
	*	@param rule id
	*/
	public function add_rule_final($time)
	{
		$db_conn=$this->db_connect_trap();
		if ($this->trap_action==null) 
		{
			$this->trap_action='No action';
		}
		$sql="UPDATE ".$this->db_prefix."received SET process_time = '".$time."' , status_detail='".$this->trap_action."'  WHERE (id = '".$this->trap_id."');";
		if ($db_conn->query($sql) == FALSE) {
			$this->trapLog('Error in update query : ' . $sql,WARN,'');
		}
	}
	
	/*********** UTILITIES *********************/
	
	/** Create database schema 
	*	@param $schema_file	string File to read schema from
	*	@param $table_prefix string to replace #PREFIX# in schema file by this
	*/
	public function create_schema($schema_file,$table_prefix)
	{
		//Read data from snmptrapd from stdin
		$input_stream=fopen($schema_file, 'r');

		if ($input_stream==FALSE)
		{
			$this->trapLog("Error reading schema !",ERROR,''); 
		}
		$newline='';
		$cur_table='';
		$cur_table_array=array();
		$db_conn=$this->db_connect_trap();
		
		while (($line=fgets($input_stream)) !== false)
		{
			$newline.=chop(preg_replace('/#PREFIX#/',$table_prefix,$line));
			if (preg_match('/; *$/', $newline)) 
            {
                $sql= $newline;
                if ($db_conn->query($sql) == FALSE) {
                    $this->trapLog('Error create schema : '.$sql,ERROR,'');
                }
                if (preg_match('/^ *CREATE TABLE ([^ ]+)/',$newline,$cur_table_array))
                {
                    $cur_table='table '.$cur_table_array[1];
                }
                else
                {
                    $cur_table='secret SQL stuff :-)';
                }
                $this->trapLog('Creating : ' . $cur_table, 3,'');
                $newline='';
            }
		}
		
		$sql= $newline;
		if ($sql != '')
		{
    		if ($db_conn->query($sql) == FALSE) {
    			$this->trapLog('Error create schema : '.$sql,ERROR,'');
    		}
		}
		$this->trapLog('Schema created',3);		
	}

	/** 
	 * Update database schema from current (as set in db) to $target_version
	 *     @param $prefix string file prefix of sql update File
	 *     @param $target_version int target db version number
	 *     @param $table_prefix string to replace #PREFIX# in schema file by this
	 *     @param bool $getmsg : only get messages from version upgrades
	 *     @return string : if $getmsg=true, return messages.
	 */
	public function update_schema($prefix,$target_version,$table_prefix,$getmsg=false)
	{
	    // Get current db number
	    $db_conn=$this->db_connect_trap();
	    $sql='SELECT id,value from '.$this->db_prefix.'db_config WHERE name=\'db_version\' ';
	    $this->trapLog('SQL query : '.$sql,4,'');
	    if (($ret_code=$db_conn->query($sql)) == FALSE) {
	        $this->trapLog('Cannot get db version. Query : ' . $sql,2,'');
	        return;
	    }
	    $version=$ret_code->fetchAll();
	    $cur_version=$version[0]['value'];
	    $db_version_id=$version[0]['id'];
	    
	    if ($this->trapDBType == 'pgsql')
	    {
	        $prefix .= 'update_pgsql/schema_';
	    }
	    else
	    {
	        $prefix .= 'update_sql/schema_';
	    }
	    //echo "version all :\n";print_r($version);echo " \n $cur_ver \n";
	    if ($getmsg == true)
	    {
	        $message='';
	        $this->trapLog('getting message for upgrade',4,'');
	        while($cur_version<$target_version)
	        {
	            $cur_version++;
	            $updateFile=$prefix.'v'.($cur_version-1).'_v'.$cur_version.'.sql';
	            $input_stream=fopen($updateFile, 'r');
	            if ($input_stream==FALSE)
	            {
	                $this->trapLog("Error reading update file ". $updateFile,2,'');
	                return;
	            }
	            do { $line=fgets($input_stream); }
	            while ($line !== false && !preg_match('/#MESSAGE/',$line));
	            if ($line === false)
	            {
	                $this->trapLog("No message in file ". $updateFile,2,'');
	                return;
	            }
	            $message .= ($cur_version-1) . '->' . $cur_version. ' : ' . preg_replace('/#MESSAGE : /','',$line)."\n";
	        }
	        return $message;
	    }
	    while($cur_version<$target_version)
	    { // tODO : execute pre & post scripts
	       $cur_version++;
	       $this->trapLog('Updating to version : ' .$cur_version ,3,'');
	       $updateFile=$prefix.'v'.($cur_version-1).'_v'.$cur_version.'.sql';
	       $input_stream=fopen($updateFile, 'r');
	       if ($input_stream==FALSE)
	       {
	           $this->trapLog("Error reading update file ". $updateFile,2,'');
	           return;
	       }
	       $newline='';
	       $db_conn=$this->db_connect_trap();
	       $db_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	       while (($line=fgets($input_stream)) != FALSE)
	       {
	           if (preg_match('/^#/', $line)) continue; // ignore comment lines
	           $newline.=chop(preg_replace('/#PREFIX#/',$table_prefix,$line));
	           if (preg_match('/; *$/', $newline))
	           {
	               $sql_req=$db_conn->prepare($newline);
	               if ($sql_req->execute() == FALSE) {
	                   $this->trapLog('Error create schema : '.$newline,1,'');
	               }
	               $cur_table_array=array();
	               if (preg_match('/^ *([^ ]+) TABLE ([^ ]+)/',$newline,$cur_table_array))
	               {
	                   $cur_table=$cur_table_array[1] . ' SQL table '.$cur_table_array[2];
	               }
	               else
	               {
	                   $cur_table='secret SQL stuff :-)';
	                   $cur_table=$newline;
	               }
	               $this->trapLog('Doing : ' . $cur_table, 3,'');
	               
	               $newline='';
	           }
	       }
	       fclose($input_stream);
	       
	       //$sql= $newline;
	       //if ($db_conn->query($sql) == FALSE) {
	       //    $this->trapLog('Error updating schema : '.$sql,1,'');
	       //}
	       
	       $sql='UPDATE '.$this->db_prefix.'db_config SET value='.$cur_version.' WHERE ( id = '.$db_version_id.' )';
	       $this->trapLog('SQL query : '.$sql,4,'');
	       if (($ret_code=$db_conn->query($sql)) == FALSE) {
	           $this->trapLog('Cannot update db version. Query : ' . $sql,2,'');
	           return;
	       }
	       
	       $this->trapLog('Schema updated to version : '.$cur_version ,3);
	    }
	}
	
	/** reset service to OK after time defined in rule
	*	TODO logic is : get all service in error + all rules, see if getting all rules then select services is better 
	*	@return : like a plugin : status code (0->3) <message> | <perfdata>
	**/
	public function reset_services()
	{
		// Get all services not in 'ok' state
		$sql_query="SELECT s.service_object_id,
	 UNIX_TIMESTAMP(s.last_check) AS last_check,
	s.current_state as state,
	v.name1 as host_name,
    v.name2 as service_name
	FROM icinga_servicestatus AS s 
    LEFT JOIN icinga_objects as v ON s.service_object_id=v.object_id
    WHERE s.current_state != 0;";
		$db_conn=$this->db_connect_ido();
		if (($services_db=$db_conn->query($sql_query)) == FALSE) { // set err to 1 to throw exception.
			$this->trapLog('No result in query : ' . $sql_query,ERROR,'');
		}
		$services=$services_db->fetchAll();
		
		// Get all rules
		$sql_query="SELECT host_name, service_name, revert_ok FROM ".$this->db_prefix."rules where revert_ok != 0;";
		$db_conn2=$this->db_connect_trap();
		if (($rules_db=$db_conn2->query($sql_query)) == FALSE) {
			$this->trapLog('No result in query : ' . $sql_query,ERROR,''); 
		}
		$rules=$rules_db->fetchAll();
		
		$now=date('U');
		
		$numreset=0;
		foreach ($rules as $rule)
		{
			foreach ($services as $service)
			{
				if ($service['service_name'] == $rule['service_name'] &&
					$service['host_name'] == $rule['host_name'] &&
					($service['last_check'] + $rule['revert_ok']) < $now)
				{
					$this->serviceCheckResult($service['host_name'],$service['service_name'],0,'Reset service to OK after '.$rule['revert_ok'].' seconds');
					$numreset++;
				}
			}
		}
		echo "\n";
		echo $numreset . " service(s) reset to OK\n";
		return 0;
		
	}

	
	/*********** MIB cache update functions *********************/
	
	/**
	 * Update or add an OID to database uses $this->dbOidIndex for mem cache
	 * @param string $oid
	 * @param string $mib
	 * @param string $name
	 * @param string $type
	 * @param string $textConv
	 * @param string $dispHint
	 * @param string $syntax
	 * @param string $type_enum
	 * @param string $description
	 * @return number : 0=unchanged, 1 = changed, 2=created
	 */
	public function update_oid($oid,$mib,$name,$type,$textConv,$dispHint,$syntax,$type_enum,$description=NULL)
	{
		$db_conn=$this->db_connect_trap();
		$description=$db_conn->quote($description);
		if (isset($this->dbOidIndex[$oid]))
		{
		    if ($this->dbOidIndex[$oid]['key'] == -1)
		    { // newly created.
		        return 0;
		    }
			if ( $name != $this->dbOidAll[$this->dbOidIndex[$oid]['key']]['name'] ||
			    $mib != $this->dbOidAll[$this->dbOidIndex[$oid]['key']]['mib'] ||
			    $type != $this->dbOidAll[$this->dbOidIndex[$oid]['key']]['type'] //||
			    //$textConv != $this->dbOidAll[$this->dbOidIndex[$oid]['key']]['textual_convention'] //||
			    //$dispHint != $this->dbOidAll[$this->dbOidIndex[$oid]['key']]['display_hint'] ||
			    //$syntax != $this->dbOidAll[$this->dbOidIndex[$oid]['key']]['syntax'] ||
			    //$type_enum != $this->dbOidAll[$this->dbOidIndex[$oid]['key']]['type_enum'] ||
			    //$description != $this->dbOidAll[$this->dbOidIndex[$oid]['key']]['description']
			    )
			{ // Do update
			    $sql='UPDATE '.$this->db_prefix.'mib_cache SET '.
 			    'name = :name , type = :type , mib = :mib , textual_convention = :tc , display_hint = :display_hint'. 
 			    ', syntax = :syntax, type_enum = :type_enum, description = :description '.
 			    ' WHERE id= :id';
			    $sqlQuery=$db_conn->prepare($sql);
			    
			    $sqlParam=array(
			        ':name' => $name,
			        ':type' => $type, 
			        ':mib' => $mib, 
			        ':tc' =>  ($textConv==null)?'null':$textConv , 
			        ':display_hint' => ($dispHint==null)?'null':$dispHint ,
			        ':syntax' => ($syntax==null)?'null':$syntax,
			        ':type_enum' => ($type_enum==null)?'null':$type_enum, 
			        ':description' => ($description==null)?'null':$description,
			        ':id' => $this->dbOidAll[$this->dbOidIndex[$oid]['id']]
			    );
			    
			    if ($sqlQuery->execute($sqlParam) == FALSE) {
			        $this->trapLog('Error in query : ' . $sql,ERROR,'');
			    }
			    $this->trapLog('Trap updated : '.$name . ' / OID : '.$oid,4,'');
				return 1;
			}
			else
			{
			    $this->trapLog('Trap unchanged : '.$name . ' / OID : '.$oid,4,'');
			    return 0;
			}
		}
        // create new OID.
			
		// Insert data

		$sql='INSERT INTO '.$this->db_prefix.'mib_cache '.
		      '(oid, name, type , mib, textual_convention, display_hint '.
              ', syntax, type_enum , description ) ' . 
              'values (:oid, :name , :type ,:mib ,:tc , :display_hint'.
              ', :syntax, :type_enum, :description )';
        
		if ($this->trapDBType == 'pgsql') $sql .= 'RETURNING id';
		
		$sqlQuery=$db_conn->prepare($sql);
		
		$sqlParam=array(
		    ':oid' => $oid,
		    ':name' => $name,
		    ':type' => $type,
		    ':mib' => $mib,
		    ':tc' =>  ($textConv==null)?'null':$textConv ,
		    ':display_hint' => ($dispHint==null)?'null':$dispHint ,
		    ':syntax' => ($syntax==null)?'null':$syntax,
		    ':type_enum' => ($type_enum==null)?'null':$type_enum,
		    ':description' => ($description==null)?'null':$description
		);
		
		if ($sqlQuery->execute($sqlParam) == FALSE) {
		    $this->trapLog('Error in query : ' . $sql,1,'');
		}
		
		switch ($this->trapDBType)
		{
		    case 'pgsql':
		        // Get last id to insert oid/values in secondary table
		        if (($inserted_id_ret=$sqlQuery->fetch(PDO::FETCH_ASSOC)) == FALSE) {		            
		            $this->trapLog('Error getting id - pgsql - ',1,'');
		        }
		        if (! isset($inserted_id_ret['id'])) {
		            $this->trapLog('Error getting id - pgsql - empty.',1,'');
		        }
		        $this->dbOidIndex[$oid]['id']=$inserted_id_ret['id'];
		        break;
		    case 'mysql':
		        // Get last id to insert oid/values in secondary table
		        $sql='SELECT LAST_INSERT_ID();';
		        if (($ret_code=$db_conn->query($sql)) == FALSE) {
		            $this->trapLog('Erreur getting id - mysql - ',1,'');
		        }
		        
		        $inserted_id=$ret_code->fetch(PDO::FETCH_ASSOC)['LAST_INSERT_ID()'];
		        if ($inserted_id==false) throw new Exception("Weird SQL error : last_insert_id returned false : open issue");
		        $this->dbOidIndex[$oid]['id']=$inserted_id;
		        break;
		    default:
		        $this->trapLog('Error SQL type  : '.$this->trapDBType,1,'');
		}

		// Set as newly created.
		$this->dbOidIndex[$oid]['key']=-1;
		return 2;
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
	    $dbObjects=null; // cache of objects for trap in db
	    $db_conn=$this->db_connect_trap();
	    
	    // Get id of trapmib.

	    $trapId = $this->dbOidIndex[$trapOID]['id'];
	    if ($check_existing == true)
	    {
	        // Get all objects
	        $sql='SELECT * FROM '.$this->db_prefix.'mib_cache_trap_object where trap_id='.$trapId.';';
	        $this->trapLog('SQL query get all traps: '.$sql,4,'');
	        if (($ret_code=$db_conn->query($sql)) == FALSE) {
	            $this->trapLog('No result in query : ' . $sql,1,'');
	        }
	        $dbObjectsRaw=$ret_code->fetchAll();
	        
	        foreach ($dbObjectsRaw as $val)
	        {
	            $dbObjects[$val['object_id']]=1;
	        }
	    }
	    foreach ($objects as $object)
	    {
	        $match=$snmptrans=array();
	        $retVal=0;
	        $objOid=$objTc=$objDispHint=$objSyntax=$objDesc=$objEnum=NULL;
	        $tmpdesc='';$indesc=false;
	        
	        $objMib=$trapmib;
	        exec($this->snmptranslate . ' -m ALL -M +'.$this->snmptranslate_dirs.
	            ' -On -Td '.$objMib.'::'.$object . ' 2>/dev/null',$snmptrans,$retVal);
	        if ($retVal!=0)
	        {
	            // Maybe not trap mib, search with IR
	            exec($this->snmptranslate . ' -m ALL -M +'.$this->snmptranslate_dirs.
	                ' -IR '.$object . ' 2>/dev/null',$snmptrans,$retVal);
	            if ($retVal != 0 || !preg_match('/(.*)::(.*)/',$snmptrans[0],$match))
	            { // Not found -> continue with warning
	               $this->trapLog('Error finding trap object : '.$trapmib.'::'.$object,2,'');
	               continue;
	            }
	            $objMib=$match[1];
	            
	            // Do the snmptranslate again.
	            exec($this->snmptranslate . ' -m ALL -M +'.$this->snmptranslate_dirs.
	                ' -On -Td '.$objMib.'::'.$object,$snmptrans,$retVal);
	            if ($retVal!=0) {
	                $this->trapLog('Error finding trap object : '.$objMib.'::'.$object,2,'');
	            }
	            
	        }
	        foreach ($snmptrans as $line)
	        {
	            if ($indesc==true)
	            {
	                $line=preg_replace('/[\t ]+/',' ',$line);
	                if (preg_match('/(.*)"$/', $line,$match))
	                {
	                    $objDesc = $tmpdesc . $match[1];
	                    $indesc=false;
	                }
	                $tmpdesc.=$line;
	                continue;
	            }
	            if (preg_match('/^\.[0-9\.]+$/', $line))
	            {
	                $objOid=$line;
	                continue;
	            }
	            if (preg_match('/^[\t ]+SYNTAX[\t ]+([^{]*) \{(.*)\}/',$line,$match))
	            {
	                $objSyntax=$match[1];
                    $objEnum=$match[2];
	                continue;
	            }
	            if (preg_match('/^[\t ]+SYNTAX[\t ]+(.*)/',$line,$match))
	            {
	                $objSyntax=$match[1];
	                continue;
	            }
	            if (preg_match('/^[\t ]+DISPLAY-HINT[\t ]+"(.*)"/',$line,$match))
	            {
	                $objDispHint=$match[1];
	                continue;
	            }
	            if (preg_match('/^[\t ]+DESCRIPTION[\t ]+"(.*)"/',$line,$match))
	            {
	                $objDesc=$match[1];
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
	                $objTc=$match[1];
	                continue;
	            }
	        }
	        $this->trapLog("Adding trap $object : $objOid / $objSyntax / $objEnum / $objDispHint / $objTc",4,'');
	        //echo "$object : $objOid / $objSyntax / $objEnum / $objDispHint / $objTc / $objDesc\n";
	        // Update 
	        $this->update_oid($objOid, $objMib, $object, '3', $objTc, $objDispHint, $objSyntax, $objEnum,$objDesc);
            
	        if (isset($dbObjects[$this->dbOidIndex[$objOid]['id']]))
	        {   // if link exists, continue
	            $dbObjects[$this->dbOidIndex[$objOid]['id']]=2;
	            continue;
	        }
	        if ($check_existing == true) 
	        {
	            // TODO : check link trap - objects exists, mark them.
	        }
	        // Associate in object table
	        $sql='INSERT INTO '.$this->db_prefix.'mib_cache_trap_object (trap_id,object_id) '.
	   	        'values (:trap_id, :object_id)';	        
	        $sqlQuery=$db_conn->prepare($sql);	        
	        $sqlParam=array(
	            ':trap_id' => $trapId,
	            ':object_id' => $this->dbOidIndex[$objOid]['id'],
	        );
	        
	        if ($sqlQuery->execute($sqlParam) == FALSE) {
	            $this->trapLog('Error adding trap object : ' . $sql . ' / ' . $trapId . '/'. $this->dbOidIndex[$objOid]['id'] ,1,'');
	        }
	    }
	    if ($check_existing == true)
	    {
	        // TODO : remove link trap - objects that wasn't marked.
	    }
	    
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
		// Timing 
		$timeTaken = microtime(true);
		$retVal=0;
		// Get all mib objects from all mibs
		$snmpCommand=$this->snmptranslate . ' -m ALL -M +'.$this->snmptranslate_dirs.' -On -Tto 2>/dev/null';
		$this->trapLog('Getting all traps : '.$snmpCommand,4,'');
		unset($this->objectsAll);
		exec($snmpCommand,$this->objectsAll,$retVal);		
		if ($retVal!=0)
		{
			$this->trapLog('error executing snmptranslate',ERROR,'');
		}
		
		// Get all mibs from databse to have a memory index
		
		$db_conn=$this->db_connect_trap();
		
		$sql='SELECT * from '.$this->db_prefix.'mib_cache;';
		$this->trapLog('SQL query : '.$sql,4,'');
		if (($ret_code=$db_conn->query($sql)) == FALSE) {
			$this->trapLog('No result in query : ' . $sql,ERROR,'');
		}
		$this->dbOidAll=$ret_code->fetchAll();
		$this->dbOidIndex=array();
		// Create the index for db;
		foreach($this->dbOidAll as $key=>$val)
		{
			$this->dbOidIndex[$val['oid']]['key']=$key;
			$this->dbOidIndex[$val['oid']]['id']=$val['id'];
		}
		
		// Count elements to show progress
		$numElements=count($this->objectsAll);
		$this->trapLog('Total snmp objects returned by snmptranslate : '.$numElements,3,'');
		
		$step=$basestep=$numElements/10; // output display of % done
		$num_step=0;
		$timeFiveSec = microtime(true); // Used for display a '.' every <n> seconds
		
		// Create index for trap objects
		$this->trapObjectsIndex=array();
		
		// detailed timing (time_* vars)
		$time_parse1=$time_check1=$time_check2=$time_check3=$time_update=$time_objects=0;
		$time_parse1N=$time_check1N=$time_check2N=$time_check3N=$time_updateN=$time_objectsN=0;
		$time_num_traps=0;
		
		for ($curElement=0;$curElement < $numElements;$curElement++)
		{
		    $time_1= microtime(true);
			if ((microtime(true)-$timeFiveSec) > 2 && $display_progress)
			{ // echo a . every 2 sec
				echo '.';
				$timeFiveSec = microtime(true);
			}
			if ($curElement>$step) 
			{ // display progress
				$num_step++;
				$step+=$basestep;
				if ($display_progress)
				{				
					echo "\n" . ($num_step*10). '% : ';
				}
			}
			// Get oid or pass if not found
			if (!preg_match('/^\.[0-9\.]+$/',$this->objectsAll[$curElement]))
			{
			    $time_parse1 += microtime(true) - $time_1;
			    $time_parse1N ++;
				continue;
			}
			$oid=$this->objectsAll[$curElement];
			
			// get next line 
			$curElement++;
			$match=$snmptrans=array();
			if (!preg_match('/ +([^\(]+)\(.+\) type=([0-9]+)( tc=([0-9]+))?( hint=(.+))?/',
						$this->objectsAll[$curElement],$match))
			{
			    $time_check1 += microtime(true) - $time_1;
				$time_check1N++;
				continue;
			}
			
			$name=$match[1]; // Name 
			$type=$match[2]; // type (21=trap, 0: may be trap, else : not trap
			
			if ($type==0) // object type=0 : check if v1 trap
			{
				// Check if next is suboid -> in that case is cannot be a trap
				if (preg_match("/^$oid/",$this->objectsAll[$curElement+1]))
				{
				    $time_check2 += microtime(true) - $time_1;
				    $time_check2N++;
					continue;
				}		
				unset($snmptrans);
				exec($this->snmptranslate . ' -m ALL -M +'.$this->snmptranslate_dirs.
					' -Td '.$oid . ' | grep OBJECTS ',$snmptrans,$retVal);
				if ($retVal!=0)
				{
				    $time_check2 += microtime(true) - $time_1;
				    $time_check2N++;
					continue;
				}
				//echo "\n v1 trap found : $oid \n";
				// Force as trap.
				$type=21;
			}
			if ($onlyTraps==true && $type!=21) // if only traps and not a trap, continue
			{
			    $time_check3 += microtime(true) - $time_1;
				$time_check3N++;
				continue;
			}
			
			$time_num_traps++;
			
			$this->trapLog('Found trap : '.$match[1] . ' / OID : '.$oid,3,'');
			if ($display_progress) echo '#'; // echo a # when trap found
				
			// get trap objects & source MIB
			unset($snmptrans);
			exec($this->snmptranslate . ' -m ALL -M +'.$this->snmptranslate_dirs.
					' -Td '.$oid,$snmptrans,$retVal);
			if ($retVal!=0)
			{
				$this->trapLog('error executing snmptranslate',ERROR,'');
			}
			
			if (!preg_match('/^(.*)::/',$snmptrans[0],$match))
			{
			    $this->trapLog('Error getting mib from trap '.$oid.' : ' . $snmptrans[0],1,'');
			}
			$trapMib=$match[1];
			
			$numLine=1;$trapDesc='';
			while (isset($snmptrans[$numLine]) && !preg_match('/^[\t ]+DESCRIPTION[\t ]+"(.*)/',$snmptrans[$numLine],$match)) $numLine++;
			if (isset($snmptrans[$numLine]))
			{
			    $snmptrans[$numLine] = preg_replace('/^[\t ]+DESCRIPTION[\t ]+"/','',$snmptrans[$numLine]);

			    while (isset($snmptrans[$numLine]) && !preg_match('/"/',$snmptrans[$numLine]))
			    {
			        $trapDesc.=preg_replace('/[\t ]+/',' ',$snmptrans[$numLine]);
			        $numLine++;
			    }
			    if (isset($snmptrans[$numLine])) {
			        $trapDesc.=preg_replace('/".*/','',$snmptrans[$numLine]);
			        $trapDesc=preg_replace('/[\t ]+/',' ',$trapDesc);
			    }

			}
			$update=$this->update_oid($oid,$trapMib,$name,$type,NULL,NULL,NULL,NULL,$trapDesc);
			$time_update += microtime(true) - $time_1; $time_1= microtime(true);
			
			if (($update==0) && ($check_change==false))
			{ // Trapd didn't change & force check disabled
			    $time_objects += microtime(true) - $time_1;
			    if ($display_progress) echo "C";
			    continue;
			}
			
			$synt=null;
			foreach ($snmptrans as $line)
			{	
    			if (preg_match('/OBJECTS.*\{([^\}]+)\}/',$line,$match))
    				{
    					$synt=$match[1];
    				}
			}
			if ($synt == null) 
			{
				//echo "No objects for $trapOID\n";
			    $time_objects += microtime(true) - $time_1;
				continue;
			}
			//echo "$synt \n";
			$trapObjects=array();
			while (preg_match('/ *([^ ,]+) *,* */',$synt,$match))
			{
				array_push($trapObjects,$match[1]);
				$synt=preg_replace('/'.$match[0].'/','',$synt);
			}
			
			$this->trap_objects($oid, $trapMib, $trapObjects, false);
			
			$time_objects += microtime(true) - $time_1;
			$time_objectsN++;
		}
		
		if ($display_progress)
		{
    		echo "\nNumber of processed traps : $time_num_traps \n";
    		echo "\nParsing : " . number_format($time_parse1+$time_check1,1) ." sec / " . ($time_parse1N+ $time_check1N)  . " occurences\n";
    		echo "Detecting traps : " . number_format($time_check2+$time_check3,1) . " sec / " . ($time_check2N+$time_check3N) ." occurences\n";
    		echo "Trap processing ($time_updateN): ".number_format($time_update,1)." sec , ";
    		echo "Objects processing ($time_objectsN) : ".number_format($time_objects,1)." sec \n";
		}
		
		// Timing ends
		$timeTaken=microtime(true) - $timeTaken;
		if ($display_progress)
		{
		    echo "Global time : ".round($timeTaken)." seconds\n";
		}
		
	}
	
}

?>
