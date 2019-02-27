<?php
namespace PitsDB;
class Db extends PDO
{
    private $db_toTimes = Array(); // global cache array
    private $db_to = Array(); // global cache array
    private $logdir = "";
    private $maxLogSize = 1;//in Mb
    private $___cache___;

    //Instanz des HTML purifiers
    private $HTMLPurifier = null;
    //private $db_config_file = __DIR__ . "/configuration.php";
    //private $db_config_dev_file = __DIR__ . "/configuration_dev.php";

    /**
     * db constructor.
     * @param $config
     */
    function __construct($config = null)
    {
        $this->logdir = __DIR__ . "/../sql-logs/";
        $config['db_type'] = isset($config['db_type']) ? $config['db_type'] : 'mysql';

        $db_charset = isset($config['db_charset']) ? $config['db_charset'] : "UTF8";
        $db_dsn     = $config['db_type'].':host='.$config['db_host'].';dbname='.$config['db_name'].';charset='.$db_charset;
        $db_user    = $config['db_user'];
        $db_pass    = $config['db_password'];


        try {
            parent::__construct($db_dsn,$db_user,$db_pass,array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION sql_mode="NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION"'));
            $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->setAttribute(PDO::MYSQL_ATTR_COMPRESS, true);
            $this->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
            $this->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY ,true);
            $this->setAttribute(PDO::ATTR_STRINGIFY_FETCHES ,true);

        } catch(PDOException $e){
            $errorMsg = 'Connecting to database failed - '. $e->getMessage();
            writelog("Error Connecting to database", array(
                "connection_string" => $db_dsn
            ));
            //die('<pre>' . $errorMsg . PHP_EOL . print_r($config, true) . PHP_EOL . print_r($db_dsn, true) . '</pre>');
            die('<pre>' . $errorMsg . PHP_EOL . print_r($db_dsn, true) . '</pre>');
        }
    }



    public function getLastInsertId(){
        return $this->lastInsertId();
    }

    /**
     *
     */
    private function checkDoubleVariables(&$sql,&$params){
        $keys = array();
        $news = array();
        if(preg_match_all("/:([a-zA-Z0-9_]+)/",$sql,$preg)){
            foreach ($preg[1] AS $key){
                if(isset($params[$key])) {
                    if (!in_array($key, $keys)) {
                        $keys[] = $key;
                    } else {
                        $news[$key][] = $key . "__auto__" . count($news);
                    }
                }
            }
            if(count($news)>0){
                foreach ($news AS $key => $value){
                    $_n = array(":".$key);
                    foreach ($value AS $newid){
                        $_n[] = ":".$newid;
                        $params[$newid] = $params[$key];
                    }

                    $gecet = explode(":".$key,$sql);
                    $sql = $gecet[0];
                    for ($x=1;$x<count($gecet);$x++){
                        $sql.=$_n[$x-1].$gecet[$x];
                    }
                }
                /*
                echo $sql;
                exit();
                */
            }
        }
    }

    /**
     * Get Data from Database
     *
     * @param string $sql: already properly escaped sql string
     * @param bool|string $field: array key by field value
     * @param array $params: Set Array of Query Params
     * @param bool $useLastSql: Use last executed Statement
     * @param bool $html: if values should have encoded html special chars
     * @param bool|string $groupby: field name on which result is grouped by
     * @param bool $withNumRows: saves numRows inside parameters if set
     * @param bool $doNotCheckDataStatus: if data_status must be contained within where clause
     * @param bool $rewriteForViews: if sql should be rewritten to fetch data from personal view
     *
     * @return array|bool (array,array key = id)/false if no value
     * !special value for field="@flat"; if set, an array result like [column1]=column2 is returned, only possible for 2 column queries
     * !special value for field="@simple"; if result is just a single value this value is returned
     * !special value for field="@line"; only one line is returned
     * !special value for field="@raw"; all result lines are returned within a numbered index array
     */
    public function fromDatabase($sql, $field = false, $params = array(), $html = false, $groupby = false)
    {
        //echo $sql."<br>";
        if(is_bool($params)){
            $html = $params;
            $params = array();
        }
        if (!$sql) {
            die('sql statement missing!');
        }

        if (!$field) {
            die('field missing!');
        }
        if (count($params) > 1) {
            //  $this->checkDoubleVariables($sql, $params);
        }


        $resultArray = array();
        $result = $this->cachedQuery($sql);


        if(!$result){
            $this->writeLog("SQL Error: ".$sql, null, [
                "sql" => $sql,
                "params" => $params
            ]);
        }
        try {


            $err = $result->execute($params);


        } catch (PDOException $e) {



            //die('sql error for: ' . $sql . ' - ' . $e->getMessage());
            $this->writeLog('sql error for: ' . $sql . ' - ' . $e->getMessage(),  $e, [
                    "sql" => $sql,
                    "params" => $params

                ]
            );
        }

        //do we have a db error?
        if ($err === false) {
            //error occured, show the error:
            //die($result->errorInfo()[2]);
            //throw new Exception($result->errorInfo()[2])
            //ichweiss

            //printr($result->errorInfo());
            //die();

            $this->writeLog(
                'sql error for: '.$sql.' - '.$result->errorInfo()
            );


        }


        if (($field != '@flat') && ($field != '@simple') && ($field != '@raw') && ($field != '@line') && ($field != '@groupby')) {
            if ($result->rowCount()) {

                $resultArrayTmp = $result->fetchAll(PDO::FETCH_ASSOC);

                foreach($resultArrayTmp as $temp){
                    $resultArray[$temp[$field]] = $temp;
                }

                $result->closeCursor();

                /*
                while ($temp = $result->fetch(PDO::FETCH_ASSOC)) {
                    $resultArray[$temp[$field]] = $temp;
                }
                */


                return ($resultArray);

            } else {

                return false;

            }
        }

        if ($field == '@flat') {
            if ($result->rowCount()) {

                $tmpData = $result->fetchAll();
                //printr($tmpData);

                foreach($tmpData as $temp){
                    $resultArray[$temp[0]] = $temp[1];
                }

                //Removed by Michel @15.08.2018 - this is not working :(
                /*
                while ($temp = $result->fetch(PDO::FETCH_ASSOC,PDO::FETCH_ORI_FIRST)) {
                    $resultArray[$temp[0]] = $temp[1];
                }
                */

                return ($resultArray);
            } else {


                return (false);
            }
        }

        if ($field == '@simple') {
            if ($result->rowCount()) {

                $temp = $result->fetch(PDO::FETCH_ASSOC);
                $resultArray = array_values($temp);
                if ($html) {
                    $resultArray[0] = htmlspecialchars($resultArray[0], ENT_QUOTES);
                }
                return ($resultArray[0]);
            } else {
                return (false);
            }
        }

        if ($field == '@line') {
            if ($result->rowCount()) {

                $resultArray = $result->fetch(PDO::FETCH_ASSOC);
                $result->closeCursor();

                if ($html) {
                    foreach ($resultArray as $key => $value) {
                        $resultArray[$key] = htmlspecialchars($value, ENT_QUOTES);
                    }
                }
                return ($resultArray);
            } else {


                return (false);
            }
        }

        if ($field == '@raw') {
            $i = 0;
            if ($result->rowCount()) {

                $resultArray = $result->fetchAll(PDO::FETCH_ASSOC);
                $result->closeCursor();

                //printr($resultArray);



                /*
                    while ($temp = $result->fetch(PDO::FETCH_ASSOC)) {
                        $resultArray[$i] = $temp;
                        $i++;
                    }
                  */

            }


            return ($resultArray);
        }
        /**
         * Für dem Listen
         */
        if ($field == "@groupby") {

            if ($result->rowCount()) {
                $i = 0;
                $last_groupby = "";
                $resultArray = "";

                while ($temp = $result->fetch(PDO::FETCH_ASSOC)) {
                    if ($groupby == 'Name') {
                        if ($temp[$groupby] != $last_groupby) {
                            $i = 0;
                        }
                        $resultArray[$temp[$groupby]][$i] = $temp;
                        $last_groupby = $temp[$groupby];
                    }
                    else{
                        if ($temp[$groupby] != $last_groupby) {
                            $i = 0;
                        }
                        $resultArray[$temp[$groupby]][$i] = $temp;
                        $last_groupby = $temp[$groupby];
                    }

                    $i++;
                }
            }
            return ($resultArray);
        }
        else {
            return (false);
        }

        return (false);
    }

    //Inizialize the HTML purifer
    private function initHTMLpurifer(){
        if(class_exists("HTMLPurifier")){
            $config = HTMLPurifier_Config::createDefault();

            $config->set("HTML", "AllowedAttributes", "style,class,id,href,src,width,height,valign,border,cellspacing,cellpadding");
            $config->set("HTML", "Allowed", "b,p,br,i,em,del,sup,sub,strong,pre,nobr,img,div,span,table,tbody,thead,tfoot,th,tr,td,ul,li,ol,a,h1,h2,h3,h3,h4,h5,h6,center,font,hr,ins,s,small");
            $config->set("HTML", "ForbiddenElements", "iframe,frame,script");

            $this->HTMLPurifier = new HTMLPurifier($config);
        }
    }

    //Make the html code more secure
    private function purifyHtml($html){
        if($this->HTMLPurifier === null){
            //Try to initialize the library first (will be initialized only once):
            if(class_exists("HTMLPurifier")){
                $this->initHTMLpurifer();
            }
        }

        if($this->HTMLPurifier === null){
            //HTML Purifer Library is NOT installed :(
            return $html;
        }

        //Purify the html code:
        return $this->HTMLPurifier->purify($html);
    }

    //Escape an array with html entities
    public function escapeArray($ar, $allowHtml = false){
        if(!is_array($ar)){
            return [];
        }

        $cleanArray = [];
        foreach($ar as $key => $val){

            if($allowHtml === true){
                //HTML ist erlaubt, aber trotz dem werden wir den Quellcode sauber von Ungezifer halten mit Purify:
                //var_dump($val);
                $val = $this->purifyHtml($val);
                //var_dump($val);
                //die();


            }elseif($allowHtml === false){
                //HTML ist NICHT erlaubt, d.h. alle HTML Zeichen werden durch htmlspecialchars escaped:
                $val = htmlspecialchars($val);
            }else{
                //do nothing with the values ($allowHtml should be no a bool value)
            }

            $cleanArray[$key] = $val;
        }

        return $cleanArray;


    }

    /**
     * @param
     * $params [array] : array of parameters to substitute
     * $allowHtml [bool] : if true, html is allowed, will be cleaned anyways from Ungezifer.
     * if false, no html is allowed, and all special chars like < > will be escaped.
     *
     * @return: return value of mysql_query
     */
    public function toDatabase($sql, $params = array(), $allowHtml = false)
    {

        if (!$sql) {
            //die('sql statement empty!');
            throw new Exception("SQL statement empty!");
        }

        $params = $this->escapeArray($params, $allowHtml);

        $mysql_queryPrepared = $this->cachedQuery($sql);

        if($mysql_queryPrepared === false) {
            //$this->writeLog('sql error for: ' . $sql);
            return false;
        }

        try
        {
            $mysql_queryReturn = $mysql_queryPrepared->execute($params);
        }
        catch(PDOException $e)
        {
            $this->writeLog('sql error for: '.$sql.' - '.$e->getMessage());
            return false;
            //#die('sql error for: '.$sql.' - '.$e->getMessage());
        }catch(Exception $e){
            printr($e);
        }


        /*
          //do we have a db error?
          $err = $mysql_queryReturn;
          if (!$err)
          {



              //error occured, show the error:
              print_r($err->errorInfo());
              die();

          }

          */

        return (true);

    }

    /**
    @param
    $table: name of table
    $id: which id

     */

    public function existsById($table,$id)
    {
        global $db;

        if (!$table)
        {
            die('no table specified!');
        }

        if (!$id)
        {
            die('no id specified');
        }

        $sql = 'SELECT id FROM `'.$table.'` WHERE id=?';

        $doesExist = $this->fromDatabase($sql,'@simple',array($id));
        if ($doesExist)
        {
            return(true);
        }
        else
        {
            return(false);
        }

    }

    /**
     * @param $table
     * @param $column
     * Prüfen, ob diese Spalte gibt
     */
    public function columnExists($table,$column){
        $result = $this->fromDatabase("SHOW COLUMNS FROM `".$table."` LIKE '".$column."'","@simple");
        if(empty($result)){
            return false;
        }
        return true;
    }

    public function tableExists($table){
        $result = $this->fromDatabase("SHOW TABLES LIKE '".$table."'","@simple");
        if(empty($result)){
            return false;
        }
        return true;
    }






    /*
 @param
 $sql[string]: sql statement to check
 @return:
 $link: link to prepared statement
  */
    private function cachedQuery($sql)
    {
        $md5 = md5($sql);
        $link = null;

        if (isset($this->db_to[$md5])) {
            // already in library
            // update time
            $microtime                 = microtime(true);
            $this->db_to[$md5]['time'] = $microtime;
            unset($this->db_toTimes[array_search($md5, $this->db_toTimes)]);
            $this->db_toTimes[$microtime] = $md5;
        } else {
            // prepare statement and get into local $link variable

            try
            {
                $link = $this->prepare($sql);
            } catch (PDOException $e) {
                if (isset($GLOBALS['sqlDebug']) && $GLOBALS['sqlDebug'] == 110377 || getenv("SQL_DEBUG")) {
                    die('sql error for: ' . $sql . ' - ' . $e->getMessage());
                } else {
                    echo $sql;

                    $extra = [
                        "sql" => $sql
                    ];

                    $this->writeLog($e->getMessage() . " - QUERY: " . $sql, $e, $extra);
                    return false;
                }
            }
            // check how many statements already cached and if limit reached (assume 10)

            if (count($this->db_to) > 50) {
                // overwrite oldest entry
                $oldest = min(array_keys($this->db_toTimes));
                unset($this->db_to[$this->db_toTimes[$oldest]]);
                unset($this->db_toTimes[$oldest]);
                $microtime                    = microtime(true);
                $this->db_toTimes[$microtime] = $md5;
                $this->db_to[$md5]['time']    = $microtime;
                $this->db_to[$md5]['link']    = $link;
            } else {
                $microtime                    = microtime(true);
                $this->db_toTimes[$microtime] = $md5;
                $this->db_to[$md5]['time']    = $microtime;
                $this->db_to[$md5]['link']    = $link;
            }

        }


        return ($this->db_to[$md5]['link']);
    }


    /**
     * @param $array
     * @param $table
     * @param $id Wenn das nicht null, dann wird das UPDATE, sonder INSERT
     */
    public function arrayToDatabase($array,$table,$id = array()){
        if(!is_array($array)){
            return false;
        }
        if(empty($table)){
            return false;
        }
        if(empty($id)){
            $query = "INSERT INTO `".$table."` (";
            $x = 0;
            foreach ($array AS $key => $value){
                if($x>0){
                    $query.=",";
                }
                $query.="`".$key."`";
                $x++;
            }
            $query.=") VALUES (";
            $x=0;
            foreach ($array AS $key => $value){
                if($x>0){
                    $query.=",";
                }
                $query.=":".$key."";
                $x++;
            }
            $query.=")";
        }
        else{
            $query = "UPDATE `".$table."` SET ";
            $x = 0;
            foreach ($array AS $key => $value){
                if($x>0){
                    $query.=",";
                }
                $query.="`".$key."`=:".$key;
                $x++;
            }
            $k = array_keys($id);
            $query.=" WHERE ".$k[0]."=:".$k[0];
            $array[$k[0]] = $id[$k[0]];
        }

        return $this->toDatabase($query,$array);
    }
    private function writeLog($msg, $e = null, $extra = []){
        if(!is_dir($this->logdir)) {
            return false;
        }

        global $composer;

        if($composer->sentry !== null){
            if($e !== null){
                $composer->sentry->captureMessage($e, $extra);
            }else{
                $composer->sentry->captureMessage($msg, $extra);
            }
        }

        $fg = debug_backtrace();
        $line = $fg[count($fg)-1]["line"];
        $host = isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : '???';

        $user = $username = getParameter("username");

        $complete_message = <<<msg
{$_SERVER["SCRIPT_NAME"]} (User: {$user})
Line: $line
$msg
msg;

        //Send Pushbullet message to channel "PITS_ERM"
        pushbullet($complete_message, "DB ERROR");


        /*
        $info = array(
            "date" => date("Y-m-d H:i:s"),
            "session" => $_SESSION,
            "server" => $_SERVER,
            "debub" => $complete_message
        );
        $info = serialize($info);
        $fp = fopen($this->logdir."ed-".md5(time().microtime(false)."-".mt_rand(0,9999)).".log","w");
        fwrite($fp,$info);
        fclose($fp);
*/
        $this->checkLogFile();
        $fp = fopen($this->logdir."error.log","a");
        //str_replace(["\r\n","\n","\t","\r","\x0B","\0"],"",$msg)
        $ref = isset($_SERVER["HTTP_REFERER"]) ? $_SERVER["HTTP_REFERER"] : '';
        fwrite($fp,"[".date("Y-m-d H:i:s")."] file:".$_SERVER["SCRIPT_NAME"]." referer:".$ref." line:".$line." msg: ".str_replace(["\r\n","\n","\t","\r","\x0B","\0"],"",$msg)."\n");
        fclose($fp);
    }
    private function checkLogFile(){

        if(!is_file($this->logdir."error.log")){
            touch($this->logdir."error.log");
        }
        $size = filesize($this->logdir."error.log")/1024/1024;
        if($size>$this->maxLogSize){
            $content = file_get_contents($this->logdir."error.log");
            $fp = fopen($this->logdir."error-".date("Y-m-d").".log","w");
            fwrite($fp,$content);
            fclose($fp);
            $fp = fopen($this->logdir."error.log","w");
            fwrite($fp,"");
            fclose($fp);
        }
    }
}