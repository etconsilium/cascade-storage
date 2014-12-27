<?php namespace Storage;

use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\MemcacheCache;
use Doctrine\Common\Cache\MemcachedCache;
use Doctrine\Common\Cache\MongoDBCache;
use Doctrine\Common\Cache\PhpFileCache;
use Doctrine\Common\Cache\RedisCache;
use Doctrine\Common\Cache\XcacheCache;
use Doctrine\Common\Cache\ZendDataCache;

/**
 * Description of CacheSpooler
 *
 * @author vs
 */
class Spooler implements \Doctrine\Common\Cache\Cache{

    protected $_tmpdir;
    protected $_spool=[];
    protected $_priority=[];
    protected $_mem_start;
    protected $_mem_limit;

    function __construct($cache_list=null, $default_temp=null) {
        $this->_mem_start=memory_get_usage();
        $this->init($cache_list, $default_temp);
        return $this;
    }

    public function init($configs, $default_temp=null){
        //  второй параметр злой хардкод
        $this->_tmpdir = empty($default_temp) ? sys_get_temp_dir().DIRECTORY_SEPARATOR.APP_ID : $default_temp ;
        foreach ($configs as $id=>$c) {
            $prt=array_merge(['save'=>50,'delete'=>50,'fetch'=>50,'precheck'=>false],(!empty($c['priority'])?$c['priority']:[]));
            $cache=$c['adapter'];
            switch (strtolower($cache['name'])) :
                case 'apc':
                    $this->_spool[$id]=new \Doctrine\Common\Cache\ApcCache;
                    break;
//                case 'array':
//                    $this->_spool[$id]=new \Doctrine\Common\Cache\ArrayCache;   //  bug inside
//                    break;
//                case 'Couchbase': //  pecl require
//                    $this->_spool[$id]=(new \Doctrine\Common\Cache\CouchbaseCache())->setCouchbase()
//                    break;
                case 'filesystem':
                    empty($cache['dir']) && $cache['dir']=$this->_tmpdir;
                    $this->_spool[$id]=(new FilesystemCache($cache['dir']));
                    break;
//                case 'memcache':  //  ooooldschooool
//                    $this->_spool[$id]=(new MemcacheCache())->setMemcache((new \Memcache()->addserver()));
//                    break;
                case 'memcached':
                    empty($cache['servers']) && $cache['servers'][]=['localhost',11211,50];
                    $memcached=new \Memcached(empty($cache['persistent'])?APP_ID:null);
                    $memcached->addServers($cache['servers']);
                    $this->_spool[$id]=new MemcachedCache();
                    $this->_spool[$id]->setMemcached($memcached);
                    break;
                case 'mongodb':
                    empty($cache['server']) && $cache['server']='mongodb://localhost:27017';
                    empty($cache['options']) && $cache['options']=['connection'=>true];
                    empty($cache['database']) && $cache['database']='applejackyll';
                    empty($cache['collection']) && $cache['collection']='cache';
                    //  MongoConnectionException
                    $this->_spool[$id]=(new MongoDBCache(
                        new \MongoCollection(
                            new \MongoDB(
                                new \Mongo($cache['server'],$cache['options'])
                                ,$cache['database'])
                            ,$cache['collection'])
                    ));
                    break;
                case 'phpfile':
                    empty($cache['dir']) && $cache['dir']=$this->_tmpdir;
                    $this->_spool[$id]=(new PhpFileCache($cache['dir']));
                    break;
//                case 'redis': //  pecl again
//                    $this->_spool[$id]=(new RedisCache())->setRedis((new \Redis())->connect($cache['host'],$cache['port']));
//                    break;
//                case 'riak':  //  pecl
//                    break;
//                case 'wincache':  //  wtf
//                    break;
                case 'xcache':
                    $this->_spool[$id]=new XcacheCache;
                    break;
                case 'zenddata':
                    $this->_spool[$id]=new ZendDataCache;
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf('Not supported `%s` cache adapter. Canceled',$id));
                    die;
            endswitch;

            $this->_priority['save'][$id]=$prt['save'];
            $this->_priority['delete'][$id]=$prt['delete'];
            $this->_priority['fetch'][$id]=$prt['fetch'];
            $this->_priority['precheck'][$id]=$prt['precheck'];
        }

        asort($this->_priority['save']);
        asort($this->_priority['delete']);
        asort($this->_priority['fetch']);
        $this->_priority['precheck']=array_filter($this->_priority['precheck']);

        $this->_priority['save']=array_keys($this->_priority['save']);
        $this->_priority['delete']=array_keys($this->_priority['delete']);
        $this->_priority['fetch']=array_keys($this->_priority['fetch']);
        $this->_priority['precheck']=array_keys($this->_priority['precheck']);

        return $this;
    }

    /**
     * Fetches an entry from the cache.
     *
     * @param string $id The id of the cache entry to fetch.
     *
     * @return mixed The cached data or FALSE, if no cache entry exists for the given id.
     */
    function fetch($id){
        $tmp=[]; $data=null;
        /**
         * @var $adapter \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['fetch'] as $adapter_id) {
            $adapter=&$this->_spool[$adapter_id];
            if (array_key_exists($adapter_id, $this->_priority['precheck'])) {
                if (!$adapter->contains($id)) {
                    $tmp[]=$adapter;
                    continue;
                }
                else {
                    $data=$adapter->fetch($id);
                    break;
                }
            }
            else {
                $data=$adapter->fetch($id);
                break;
            }
        }
        if (!empty($tmp)) {
            array_reverse($tmp);
            foreach ($tmp as $adapter) {
                $adapter->save($id, $data);
            }
        }
        return $data;
    }

    /**
     * Tests if an entry exists in the cache.
     *
     * @param string $id The cache id of the entry to check for.
     *
     * @return boolean TRUE if a cache entry exists for the given cache id, FALSE otherwise.
     */
    function contains($id){
        /**
         * @var $this->_spool[$adapter_id] \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['fetch'] as $adapter_id) {
            if ($this->_spool[$adapter_id]->contains($id)) return true;
        }
        return false;
    }

    /**
     * Puts data into the cache.
     *
     * @param string $id       The cache id.
     * @param mixed  $data     The cache entry/data.
     * @param int    $lifeTime The cache lifetime.
     *                         If != 0, sets a specific lifetime for this cache entry (0 => infinite lifeTime).
     * @return boolean TRUE if the entry was successfully stored in the cache, FALSE otherwise.
     */
    function save($id, $data, $lifeTime = 0){
        /**
         * @var $this->_spool[$adapter_id] \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['save'] as $adapter_id) {
            $this->_spool[$adapter_id]->save($id, $data, $lifeTime);
        }
    }

    /**
     * Deletes a cache entry.
     *
     * @param string $id The cache id.
     *
     * @return boolean TRUE if the cache entry was successfully deleted, FALSE otherwise.
     */
    function delete($id){
        /**
         * @var $this->_spool[$adapter_id] \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['delete'] as $adapter_id) {
            $this->_spool[$adapter_id]->delete($id);
        }
    }

    function getStats(){
        $stat=[];
        /**
         * @var $this->_spool[$adapter_id] \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['fetch'] as $adapter_id) {
            $stat[$adapter_id]=$this->_spool[$adapter_id]->getStats();
        }
        return $stat;
    }

    function flushAll($id=null){
        if (array_key_exists($id,$this->_spool)) {
            $this->_spool[$id]->flushAll();
            return;
        }
        /**
         * @var $adapter \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['delete'] as $adapter_id) {
            $this->_spool[$adapter_id]->flushAll();
        }
        return;
    }
}
