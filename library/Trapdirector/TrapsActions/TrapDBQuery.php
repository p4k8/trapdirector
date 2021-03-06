<?php

namespace Icinga\Module\Trapdirector\TrapsActions;

use Exception;
use Zend_Db_Expr;
use Zend_Db_Adapter_Abstract;
use Icinga\Module\Trapdirector\TrapsController;

/**
 * Database queries for UI (on Trap database)
 * Calling class must implement : getTrapCtrl , getDbConn
 * @license GPL
 * @author Patrick Proy
 * @package trapdirector
 * @subpackage UI
 *
 */
trait TrapDBQuery
{
    
    /** @return TrapsController */
    abstract protected function getTrapCtrl();

    /** @return Zend_Db_Adapter_Abstract : returns DB connexion or null on error */
    abstract public function getDbConn();
    
    /** Add handler rule in traps DB
     *	@param array $params : array(<db item>=><value>)
     *	@return int inserted id
     */
    public function addHandlerRule($params)
    {
        // TODO Check for rule consistency
        
        $dbConn = $this->getDbConn();
        if ($dbConn === null) throw new \ErrorException('uncatched db error');
        // Add last modified date = creation date and username
        $params['created'] = new Zend_Db_Expr('NOW()');
        $params['modified'] = new 	Zend_Db_Expr('NOW()');
        $params['modifier'] = $this->getTrapCtrl()->Auth()->getUser()->getUsername();
        
        $query=$dbConn->insert(
            $this->getTrapCtrl()->getModuleConfig()->getTrapRuleName(),
            $params
            );
        if($query==false)
        {
            return null;
        }
        return $dbConn->lastInsertId();
    }
    
    /** Update handler rule in traps DB
     *	@param array $params : (<db item>=><value>)
     *   @param integer $ruleID : rule id in db
     *	@return array affected rows
     */
    public function updateHandlerRule($params,$ruleID)
    {
        // TODO Check for rule consistency
        $dbConn = $this->getDbConn();
        if ($dbConn === null) throw new \ErrorException('uncatched db error');
        // Add last modified date = creation date and username
        $params['modified'] = new 	Zend_Db_Expr('NOW()');
        $params['modifier'] = $this->getTrapCtrl()->Auth()->getUser()->getUsername();
        
        $numRows=$dbConn->update(
            $this->getTrapCtrl()->getModuleConfig()->getTrapRuleName(),
            $params,
            'id='.$ruleID
            );
        return $numRows;
    }
    
    /** Delete rule by id
     *	@param int $ruleID rule id
     */
    public function deleteRule($ruleID)
    {
        if (!preg_match('/^[0-9]+$/',$ruleID)) { throw new Exception('Invalid id');  }
        
        $dbConn = $this->getDbConn();
        if ($dbConn === null) throw new \ErrorException('uncatched db error');
        
        $query=$dbConn->delete(
            $this->getTrapCtrl()->getModuleConfig()->getTrapRuleName(),
            'id='.$ruleID
            );
        return $query;
    }
    
    /** Delete trap by ip & oid
     *	@param $ipAddr string source IP (v4 or v6)
     *	@param $oid string oid
     */
    public function deleteTrap($ipAddr,$oid)
    {
        
        $dbConn = $this->getDbConn();
        if ($dbConn === null) throw new \ErrorException('uncatched db error');
        $condition=null;
        if ($ipAddr != null)
        {
            $condition="source_ip='$ipAddr'";
        }
        if ($oid != null)
        {
            $condition=($condition===null)?'':$condition.' AND ';
            $condition.="trap_oid='$oid'";
        }
        if($condition === null) return null;
        $query=$dbConn->delete(
            $this->getTrapCtrl()->getModuleConfig()->getTrapTableName(),
            $condition
            );
        // TODO test ret code etc...
        return $query;
    }
    
    
    /** count trap by ip & oid
     *	@param $ipAddr string source IP (v4 or v6)
     *	@param $oid string oid
     */
    public function countTrap($ipAddr,$oid)
    {
        
        $dbConn = $this->getDbConn();
        if ($dbConn === null) throw new \ErrorException('uncatched db error');
        
        $condition=null;
        if ($ipAddr != null)
        {
            $condition="source_ip='$ipAddr'";
        }
        if ($oid != null)
        {
            $condition=($condition===null)?'':$condition.' AND ';
            $condition.="trap_oid='$oid'";
        }
        if($condition === null) return 0;
        $query=$dbConn->select()
            ->from(
                $this->getTrapCtrl()->getModuleConfig()->getTrapTableName(),
                array('num'=>'count(*)'))
            ->where($condition);
        $returnRow=$dbConn->fetchRow($query);
        return $returnRow->num;
    }
    
    /** get configuration value
     *	@param string $element : configuration name in db
     */
    public function getDBConfigValue($element)
    {
        
        $dbConn = $this->getDbConn();
        if ($dbConn === null) throw new \ErrorException('uncatched db error');
        
        $query=$dbConn->select()
        ->from(
            $this->getTrapCtrl()->getModuleConfig()->getDbConfigTableName(),
            array('value'=>'value'))
            ->where('name=?',$element);
            $returnRow=$dbConn->fetchRow($query);
            if ($returnRow==null)  // value does not exists
            {
                $default=$this->getTrapCtrl()->getModuleConfig()->getDBConfigDefaults();
                if ( ! isset($default[$element])) return null; // no default and not value
                
                $this->addDBConfigValue($element,$default[$element]);
                return $default[$element];
            }
            if ($returnRow->value == null) // value id empty
            {
                $default=$this->getTrapCtrl()->getModuleConfig()->getDBConfigDefaults();
                if ( ! isset($default[$element])) return null; // no default and not value
                $this->setDBConfigValue($element,$default[$element]);
                return $default[$element];
            }
            return $returnRow->value;
    }
    
    /** add configuration value
     *	@param string $element : name of config element
     *   @param string $value : value
     */
    
    public function addDBConfigValue($element,$value)
    {
        
        $dbConn = $this->getDbConn();
        if ($dbConn === null) throw new \ErrorException('uncatched db error');
        
        $query=$dbConn->insert(
            $this->getTrapCtrl()->getModuleConfig()->getDbConfigTableName(),
            array(
                'name' => $element,
                'value'=>$value
            )
            );
        return $query;
    }
    
    /** set configuration value
     *	@param string $element : name of config element
     *   @param string $value : value
     */
    public function setDBConfigValue($element,$value)
    {
        
        $dbConn = $this->getDbConn();
        if ($dbConn === null) throw new \ErrorException('uncatched db error');
        
        $query=$dbConn->update(
            $this->getTrapCtrl()->getModuleConfig()->getDbConfigTableName(),
            array('value'=>$value),
            'name=\''.$element.'\''
            );
        return $query;
    }
    
    
}