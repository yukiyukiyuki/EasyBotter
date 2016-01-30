<?php
//============================================================
//EasyBotter Ver2.1.2
//updated 2013/01/08 by pha
//modified 2016/01/30 by yuki(★部分)
//============================================================
class EasyBotter
{    
        private $_screen_name;
        private $_consumer_key;
        private $_consumer_secret;
        private $_access_token;
        private $_access_token_secret;        
        private $_replyLoopLimit;
        private $_footer;
        private $_dataSeparator;        
        private $_tweetData;        
        private $_replyPatternData;        
        private $_logDataFile;
        private $_latestReply;
        
    function __construct()
    {                        
        //$dir = getcwd();
        //$path = $dir."/PEAR";
        $path = dirname(__FILE__) . "/PEAR";        
        set_include_path(get_include_path() . PATH_SEPARATOR . $path);
        $inc_path = get_include_path();
        chdir(dirname(__FILE__));
        date_default_timezone_set("Asia/Tokyo");        
        
        require_once("setting.php");
        $this->_screen_name = $screen_name;
        $this->_consumer_key = $consumer_key;
        $this->_consumer_secret = $consumer_secret;
        $this->_access_token = $access_token;
        $this->_access_token_secret = $access_token_secret;        
        $this->_replyLoopLimit = $replyLoopLimit;
        $this->_footer  = $footer;
        $this->_dataSeparator = $dataSeparator;        
        $this->_logDataFile = "log.dat";
        $this->_log = json_decode(file_get_contents($this->_logDataFile),true);
        $this->_latestReply = $this->_log["latest_reply"];
        $this->_latestReplyTimeline = $this->_log["latest_reply_tl"];                

        require_once("HTTP/OAuth/Consumer.php");  
		$this->OAuth_Consumer_build();
        $this->printHeader();
    }
       
    function __destruct(){
        $this->printFooter();        
    }

    //つぶやきデータを読み込む
    function readDataFile($file){
        if(preg_match("@\.php$@", $file) == 1){
            require_once($file);
            return $data;
        }else{
            $tweets = trim(file_get_contents($file));
            $tweets = preg_replace("@".$this->_dataSeparator."+@",$this->_dataSeparator,$tweets);
            $data = explode($this->_dataSeparator, $tweets);
            return $data;
        }
    }    
    //リプライパターンデータを読み込む
    function readPatternFile($file){
        $data = array();
        require_once($file);
        if(count($data) != 0){
            return $data;
        }else{
            return $reply_pattern;            
        }
    }    
    //どこまでリプライしたかを覚えておく
    function saveLog($name, $data){
        $this->_log[$name] = $data;
        file_put_contents($this->_logDataFile,json_encode($this->_log));        
    }        
    //表示用HTML
    function printHeader(){
        $header = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
        $header .= '<html xmlns="http://www.w3.org/1999/xhtml" lang="ja" xml:lang="ja">';
        $header .= '<head>';
        $header .= '<meta http-equiv="content-language" content="ja" />';
        $header .= '<meta http-equiv="content-type" content="text/html; charset=UTF-8" />';
        $header .= '<title>EasyBotter</title>';
        $header .= '</head>';
        $header .= '<body><pre>';
        print $header;
    }
    //表示用HTML
    function printFooter(){
        echo "</body></html>";
    }

//============================================================
//bot.phpから直接呼び出す、基本の５つの関数
//============================================================

    //★ある発言を単発でポストする
    function postone($status = "*sigh*"){
        if(empty($status)){
            $message = "投稿するメッセージがないようです。<br />";
            echo $message;
            return array("error"=> $message);
        }else{
            $status = $this->convertText($status);
            $response = $this->setUpdate(array("status"=>$status));
            return $this->showResult($response);
        }
    }

    //ランダムにポストする
    function postRandom($datafile = "data.txt"){        
        $status = $this->makeTweet($datafile);                
        if(empty($status)){
            $message = "投稿するメッセージがないようです。<br />";
            echo $message;
            return array("error"=> $message);
        }else{                
            //idなどの変換
            $status = $this->convertText($status);
            //フッターを追加
            $status .= $this->_footer;
            return $this->showResult($this->setUpdate(array("status"=>$status)), $status);            
        }    
    }    
    
    //順番にポストする
    function postRotation($datafile = "data.txt", $lastPhrase = FALSE){        
        $status = $this->makeTweet($datafile,0);                
        if($status !== $lastPhrase){
            $this->rotateData($datafile);        
            if(empty($status)){
                $message = "投稿するメッセージがないようです。<br />";
                echo $message;
                return array("error"=> $message);
            }else{                
                //idなどの変換
                $status = $this->convertText($status);    
                //フッターを追加
                $status .= $this->_footer;                       
                return $this->showResult($this->setUpdate(array("status"=>$status)), $status);            
            }
        }else{
            $message = "終了する予定のフレーズ「".$lastPhrase."」が来たので終了します。<br />";
            echo $message;
            return array("error"=> $message);
        }
    }    
    
    //リプライする
    function reply($cron = 2, $replyFile = "data.txt", $replyPatternFile = "reply_pattern.php"){
        $replyLoopLimit = $this->_replyLoopLimit;
        //リプライを取得
        $response = $this->getReplies($this->_latestReply);    
        $response = $this->getRecentTweets($response, $cron * $replyLoopLimit * 3);
        $replies = $this->getRecentTweets($response, $cron);
        $replies = $this->selectTweets($replies);
        if(count($replies) != 0){                           
            //ループチェック
            $replyUsers = array();
            foreach($response as $r){
                $replyUsers[] = $r["user"]["screen_name"];                
            }
            $countReplyUsers = array_count_values($replyUsers);
            $replies2 = array();
            foreach($replies as $rep){
                $userName = $rep["user"]["screen_name"];
                if($countReplyUsers[$userName] < $replyLoopLimit){
                    $replies2[] = $rep;
                }
            }            
            //古い順にする
            $replies2 = array_reverse($replies2);                   
            if(count($replies2) != 0){            
                //リプライの文章をつくる
                $replyTweets = $this->makeReplyTweets($replies2, $replyFile, $replyPatternFile);                
                $repliedReplies = array();
                foreach($replyTweets as $rep){
                    $response = $this->setUpdate(array("status"=>$rep["status"],'in_reply_to_status_id'=>$rep["in_reply_to_status_id"]));
                    $results[] = $this->showResult($response, $rep["status"]);            
                    if($response["in_reply_to_status_id_str"]){
                        $repliedReplies[] = $response["in_reply_to_status_id_str"];
                    }
                }
            }
        }else{
            $message = $cron."分以内に受け取った未返答のリプライはないようです。<br /><br />";
            echo $message;
            $results[] = $message;
        }
        
        //ログに記録
        if(!empty($repliedReplies)){
            rsort($repliedReplies);
            $this->saveLog("latest_reply",$repliedReplies[0]);
        }
        return $results;
    }
    
    //タイムラインに反応する
    function replyTimeline($cron = 2, $replyPatternFile = "reply_pattern.php"){
        //タイムラインを取得
        $timeline = $this->getFriendsTimeline($this->_latestReplyTimeline,100);       
        $timeline2 = $this->getRecentTweets($timeline, $cron);   
        $timeline2 = $this->selectTweets($timeline2);
        $timeline2 = array_reverse($timeline2);        
                
        if(count($timeline2) != 0){
            //リプライを作る        
            $replyTweets = $this->makeReplyTimelineTweets($timeline2, $replyPatternFile);
            if(count($replyTweets) != 0){
                $repliedTimeline = array();
                foreach($replyTweets as $rep){
                    $response = $this->setUpdate(array("status"=>$rep["status"],'in_reply_to_status_id'=>$rep["in_reply_to_status_id"]));
                    $result = $this->showResult($response, $rep["status"]);                    
                    $results[] = $result;
                    if(!empty($response["in_reply_to_status_id_str"])){
                        $repliedTimeline[] = $response["in_reply_to_status_id_str"];
                    }
                }
            }else{
                $message = $cron."分以内のタイムラインに未反応のキーワードはないみたいです。<br /><br />";
                echo $message;
                $results = $message;
            }
        }else{
            $message = $cron."分以内のタイムラインに未反応のキーワードはないみたいです。<br /><br />";
            echo $message;
            $results = $message;        
        }

        //ログに記録        
        if(!empty($repliedTimeline[0])){
            $this->saveLog("latest_reply_tl",$repliedTimeline[0]);
        }
        return $results;        
    }

    //自動フォロー返しする
    function autoFollow(){    
        $followers = $this->getFollowers();
        $friends = $this->getFriends();        
        $followlist = array_diff($followers["ids"], $friends["ids"]);        
        if($followlist){
            foreach($followlist as $id){    
                $response = $this->followUser($id);
                if(empty($response["errors"])){
                    echo $response["name"]."(@<a href='https://twitter.com/".$response["screen_name"]."'>".$response["screen_name"]."</a>)をフォローしました<br /><br />";
                }
            }
        }            
    }


//============================================================
//yukiが作った余分な機能
//============================================================

    //★聞かれたら天気を教える
    function tenkiasked($city){
        $today = date('j日');
        $msgs = array();
        $status = '';
        
        $tenki_url = "http://weather.livedoor.com/forecast/rss/area/".$city.".xml";
        
        $xml = file_get_contents($tenki_url);
        $tenki = simplexml_load_string($xml);
        
        foreach($tenki->channel->item as $k => $v){
            $content = (string)$v->title;
            if (strstr($content,$today)){
                preg_match_all('/\- (.*?) \- 最高気温/',$content,$m);//天気の文字部分を抽出
                preg_match_all('/最高気温(.*?)℃/',$content,$t);//最高気温を抽出
                $msg = $m[1][0];
                $htemp = $t[1][0];
            }
        }
        
        if ($msg){
            if(empty($htemp)){//時間帯によっては最高気温が空欄になるのでその対策
                $comm = array("今日の[[cityname]]の天気は".$msg."だそうです。最高気温の記載はないですね。","[[cityname]]ですね…今日の天気は".$msg."だそうです。最高気温の記載はこの時間だとないですね。",);//同じセリフだとつまらないので何種類か。
                $status = $comm[array_rand($comm)];
            }else{
                $comm = array("今日の[[cityname]]の天気は".$msg."で最高気温".$htemp."℃だそうですよ。","[[cityname]]ですね…えぇと、今日は".$msg."で、最高気温は".$htemp."℃だそうです。",);
                $status = $comm[array_rand($comm)];
            }
            return $status;
        }else{//何らかの理由でデータが取得できなかった時用
            $status = "…あれ？すみません、情報サイトからデータが取れなくてお教えできません。時間をおいて再挑戦して頂けますか？";
            return $status;
        }
    }

    //★しりとり
    function siritori($sirigiven = ""){
        //もらった言葉の読みを、Yahoo!形態素解析に教えてもらう。
        //$appid=''の''内に、自分のYahoo!アプリケーションIDを記入して下さい。
        $appid = '';
        $sirigiven = $this->_mMAParse($sirigiven, $appid);//Yahoo!に形態素解析してもらう
        $sirigiven = $this->_mXmlParse($sirigiven, 'reading');
        $sirigiven = implode('',$sirigiven);
        $lastsiri = mb_substr($sirigiven,-1,1,"utf-8");//最後の一文字get

        if(preg_match("/^[ぁ-ん]+$/u",$lastsiri) == 0){//最後の一文字の読み仮名がひらがなじゃない場合（！やーなど）
            $lastsiri = mb_substr($sirigiven,-2,1,"utf-8");//最後のふた文字get。これでもだめなら分からないことにします。
        }
        if($lastsiri == "ん"){
            $status = "あっ ぼくの かちです （きゃっきゃ";//相手が負けた時の反応をここに
        }elseif($lastsiri){
            if(!$timeline){
                $timeline = $this->selectTweets($this->getFriendsTimeline(NULL,100));
            }
            if((bool)$timeline){
                $meishi = array();
                foreach($timeline as $tweet){
                    $twtext = str_replace('&amp;', '&', $tweet["text"]);
                    $text .= $twtext;
                }
                    $text = $this->_mMAParse($text, $appid);
                    var_dump($text);
                    $tango = $this->_mXmlParse($text, 'surface');//surfaceは"単語"
                    $tangoyomi = $this->_mXmlParse($text, 'reading');//readingは"読みがな"
                    $hinshi = $this->_mXmlParse($text, 'pos');//posは"品詞"
                    //名詞だけ集める
                    foreach($hinshi as $key => $val){
                        if(preg_match("@名詞@u",$val,$matches) === 1){
                            if(mb_strlen($tangoyomi[$key]) > 1 && preg_match("/(^[!-~ｗ]+$|^い$|^な$|^とき$|^こと$|^かた$|^ほう$|^っ$|^ホモ$|^ほも$|^ﾎﾓ$|^ちん|^チン|^ﾁﾝ|レイプ|ﾚｲﾌﾟ|姦|セックス|せっくす|ｾｯｸｽ|性|交|^うん|^ウン|^ｳﾝ|^金玉|漏|^[こそあど]れ$|^はい$|^やめ$|^ァ$)/",$tango[$key],$matches) === 0 && mb_substr($tangoyomi[$key],0,1,"utf-8") == $lastsiri){//真ん中のpreg_matchは除外ワード。ご自由に追加してください。
                                $meishi[] = $tango[$key];
                            }
                        }
                    }
                
            }
            if($meishi){
                $siri = $meishi[array_rand($meishi)];
                if(mb_substr($siri,-1,1,"utf-8") == "ん"){//「ん」で終わる言葉を言ってしまった時のセリフ。$lastsiriは相手の言った単語のラスト1文字、$siriは返す単語。
                    $status = array("えっと えっと 『".$siri."』？ あっ！","えっと あっ 『".$siri."』 ？？","『".$lastsiri."』 ですね うー 『".$siri."』？ あっ",);
                    $status = $status[array_rand($status)];
                }else{//普通に答えられた時のセリフ
                    $status = array("えっと えっと 『".$siri."』","えっと あっ 『".$siri."』 ？？","『".$lastsiri."』 ですね うー あっ 『".$siri."』！",);
                    $status = $status[array_rand($status)];
                }
            }else{//該当する言葉が見当たらなかった時のセリフ
                $status = array("あの えっと えっと   わかんない です （しょぼ","えっと えっと   わかんない （しょぼ",);
                $status = $status[array_rand($status)];
            }
        return $status;
        }
    }
    
    //★しりとりで使う関数。Yahoo!から形態素解析の結果をもらう。
    //wktkさんの関数を使用させて頂いています。https://github.com/wktk/markov4eb
    function _mMAParse($text, $appid){
        require_once 'HTTP/Request2.php';
        $url = 'http://jlp.yahooapis.jp/MAService/V1/parse';
        $http = new HTTP_Request2($url, HTTP_Request2::METHOD_POST);
        // パラメータの設定
        $http->addPostParameter( array(
                                       'results'   => 'ma',
                                       'appid'     =>  $appid,
                                       'response'  => 'surface,pos,reading',
                                       'sentence'  =>  $text,
                                       ));
        // 送信･取得
        return $http->send()->getBody();
    }
    
    //★しりとりで使う関数。XMLから必要な部分を切り出す。
    function _mXmlParse($xml, $pat){
        preg_match_all("/<".$pat.">(.+?)<\/".$pat.">/", $xml, $match);
            return $match[1];
    }


        
//============================================================
//上の５つの関数から呼び出す関数
//============================================================
    
    //発言を作る
    function makeTweet($file, $number = FALSE){
        //txtファイルの中身を配列に格納
        if(empty($this->_tweetData[$file])){
            $this->_tweetData[$file] = $this->readDataFile($file);        
        }        
        //発言をランダムに一つ選ぶ場合
        if($number === FALSE){
            $status = $this->_tweetData[$file][array_rand($this->_tweetData[$file])];
        }else{
        //番号で指定された発言を選ぶ場合
            $status = $this->_tweetData[$file][$number];            
        }       
        return $status;
    }    
    
    //リプライを作る
    function makeReplyTweets($replies, $replyFile, $replyPatternFile){
        if(empty($this->_replyPatternData[$replyPatternFile]) && !empty($replyPatternFile)){
            $this->_replyPatternData[$replyPatternFile] = $this->readPatternFile($replyPatternFile);
        }        
        $replyTweets = array();
        
        foreach($replies as $reply){        
            $status = "";
            //★ここからしりとり改造
            if(preg_match("@(『.*』)@u",$reply["text"],$matches)){
                $sirigiven = str_replace("『","",$matches[0]);
                $sirigiven = str_replace("』","",$sirigiven);
                
                if(mb_substr($sirigiven,-1,1,"utf-8") == "ん"){//最後の一文字が既にひらがなの「ん」の場合。Yahoo!に渡さず終了。
                    $status = "あっ ぼくの かちです （きゃっきゃ";
                }else{
                    $status = $this->siritori($sirigiven);
                }
            }//★しりとりここまで。正確には下記のelseifのelseまで。
                    
            //指定されたリプライパターンと照合
            elseif(!empty($this->_replyPatternData[$replyPatternFile])){
                foreach($this->_replyPatternData[$replyPatternFile] as $pattern => $res){
                    if(preg_match("@".$pattern."@u",$reply["text"], $matches) === 1){                                        
                        $status = $res[array_rand($res)];
                        for($i=1;$i <count($matches);$i++){
                            $p = "$".$i;  //エスケープ？
                            $status = str_replace($p,$matches[$i],$status);
                        }
                        break;
                    }
                }            
            }
                         
            //リプライパターンにあてはまらなかった場合はランダムに
            if(empty($status) && !empty($replyFile)){
                $status = $this->makeTweet($replyFile);
            }
            if(empty($status) || stristr($status,"[[END]]")){
                continue;
            }            
            //idなどを変換
            $status = $this->convertText($status, $reply);
            //フッターを追加
            $status .= $this->_footer;
            //リプライ相手、リプライ元を付与
            $re["status"] = "@".$reply["user"]["screen_name"]." ".$status;
            
            //★以下天気用改造
            switch(true){
                case stristr($status, "[[tenki]]"):
                    $cdata = explode("・",$status);
                    $city = $cdata[1];
                    $cityname = $cdata[2];
                    $status = $this->tenkiasked($city);
                    $status = str_replace("[[cityname]]",$cityname,$status);
                    $re["status"] = "@".$reply["user"]["screen_name"]." ".$status;
                    break;
                default:
                    $re["status"] = "@".$reply["user"]["screen_name"]." ".$status;
            }
            //★天気ここまで
            
            $re["in_reply_to_status_id"] = $reply["id_str"];
            
            //応急処置
            if(!stristr($status,"[[END]]")){
                $replyTweets[] = $re;
            } 
        }                        
        return $replyTweets;    
    }
    
    //タイムラインへの反応を作る
    function makeReplyTimelineTweets($timeline, $replyPatternFile){
        if(empty($this->_replyPatternData[$replyPatternFile])){
            $this->_replyPatternData[$replyPatternFile] = $this->readPatternFile($replyPatternFile);
        }
        $replyTweets = array();        
        foreach($timeline as $tweet){
            $status = "";
            //リプライパターンと照合
            foreach($this->_replyPatternData[$replyPatternFile] as $pattern => $res){
                if(preg_match("@".$pattern."@u",$tweet["text"], $matches) === 1 && !preg_match("/\@/i",$tweet["text"])){                                        
                    $status = $res[array_rand($res)];
                    for($i=1;$i <count($matches);$i++){
                        $p = "$".$i;
                        $status = str_replace($p,$matches[$i],$status);
                    }
                    break;                    
                }                
            }
            if(empty($status)){
                continue;
            }
            //idなどを変換
            $status = $this->convertText($status, $tweet);
            //フッターを追加
            $status .= $this->_footer;

            //リプライ相手、リプライ元を付与
            $rep = array();
            $rep["status"] = "@".$tweet["user"]["screen_name"]." ".$status;
            $rep["in_reply_to_status_id"] = $tweet["id_str"];      
            //応急処置
            if(!stristr($status,"[[END]]")){
                $replyTweets[] = $rep;
            }
        }                        
        return $replyTweets;    
    }        
    
    //ログの順番を並び替える
    function rotateData($file){
        $tweetsData = file_get_contents($file);
        $tweets = explode("\n", $tweetsData);
        $tweets_ = array();
        for($i=0;$i<count($tweets) - 1;$i++){
            $tweets_[$i] = $tweets[$i+1];
        }
        $tweets_[] = $tweets[0];
        $tweetsData_ = "";
        foreach($tweets_ as $t){
            $tweetsData_ .= $t."\n";
        }
        $tweetsData_ = trim($tweetsData_);        
        $fp = fopen($file, 'w');
        fputs($fp, $tweetsData_);
        fclose($fp);            
    }
    
    //つぶやきの中から$minute分以内のものと、最後にリプライしたもの以降のものだけを返す
    function getRecentTweets($tweets,$minute){    
        $tweets2 = array();
        $now = strtotime("now");
        $limittime = $now - $minute * 70; //取りこぼしを防ぐために10秒多めにカウントしてる    
        foreach($tweets as $tweet){
            $time = strtotime($tweet["created_at"]);    
            if($limittime <= $time){                    
                $tweets2[] = $tweet;                
            }else{
                break;                
            }
        }    
        return $tweets2;    
    }
    
    //取得したつぶやきを条件で絞る
    function selectTweets($tweets){    
        $tweets2 = array();
        foreach($tweets as $tweet){
            //自分自身のつぶやきを除外する
            if($this->_screen_name == $tweet["user"]["screen_name"]){
                continue;
            }                        
            //RT, QTを除外する
            if(strpos($tweet["text"],"RT") != FALSE || strpos($tweet["text"],"QT") != FALSE){
                continue;
            }                        
            $tweets2[] = $tweet;                                        
        }    
        return $tweets2;    
    }                            
    
    //文章を変換する
    function convertText($text, $reply = FALSE){        
        $text = str_replace("{year}",date("Y"),$text);
        $text = str_replace("{month}",date("n"),$text);
        $text = str_replace("{day}",date("j"),$text);
        $text = str_replace("{hour}",date("G"),$text);
        $text = str_replace("{minute}",date("i"),$text);
        $text = str_replace("{second}",date("s"),$text);    
              
        //タイムラインからランダムに最近発言した人のデータを取る
        if(strpos($text,"{timeline_id}") !== FALSE){
            $randomTweet = $this->getRandomTweet();
            $text = str_replace("{timeline_id}", $randomTweet["user"]["screen_name"],$text);
        }
        if(strpos($text, "{timeline_name}") !== FALSE){
            $randomTweet = $this->getRandomTweet();
            $text = str_replace("{timeline_name}",$randomTweet["user"]["name"],$text);
        }

        //使うファイルによって違うもの
        //リプライの場合は相手のid、そうでなければfollowしているidからランダム
        if(strpos($text,"{id}") !== FALSE){
            if(!empty($reply)){
                $text = str_replace("{id}",$reply["user"]["screen_name"],$text);                
            }else{
                $randomTweet = $this->getRandomTweet();
                $text = str_replace("{id}",$randomTweet["user"]["screen_name"],$text);        
            }
        }
        if(strpos($text,"{name}") !== FALSE){
            if(!empty($reply)){
                $text = str_replace("{name}",$reply["user"]["name"],$text);                
            }else{
                $randomTweet = $this->getRandomTweet();
                $text = str_replace("{name}",$randomTweet["user"]["name"],$text);        
            }
        }
                
        //リプライをくれた相手のtweetを引用する
        if(strpos($text,"{tweet}") !== FALSE && !empty($reply)){
            $tweet = preg_replace("@\.?\@[a-zA-Z0-9-_]+\s@u","",$reply["text"]); //@リプライを消す        
            $text = str_replace("{tweet}",$tweet,$text);                                   
        }            
                
        return $text;
    }    

    //タイムラインの最近30件の呟きからランダムに一つを取得
    function getRandomTweet($num = 30){
        $response = $this->getFriendsTimeline(NULL, $num);         
        if($response["errors"]){
            echo $response["errors"][0]["message"];               
        }else{           
            for($i=0; $i < $num;$i++){             
                $randomTweet = $response[array_rand($response)];
                if($randomTweet["user"]["screen_name"] != $this->_screen_name){
                    return $randomTweet;                
                }
            }
        }
        return false;
    }
    
    //結果を表示する
    function showResult($response, $status = NULL){    
        if(empty($response["errors"])){
            $message = "Twitterへの投稿に成功しました。<br />";
            $message .= "@<a href='http://twitter.com/".$response["user"]["screen_name"]."' target='_blank'>".$response["user"]["screen_name"]."</a>";
            $message .= "に投稿したメッセージ：".$response["text"];
            $message .= " <a href='http://twitter.com/".$response["user"]["screen_name"]."/status/".$response["id_str"]."' target='_blank'>http://twitter.com/".$response["user"]["screen_name"]."/status/".$response["id_str"]."</a><br /><br />";
            echo $message;
            return array("result"=> $message);
        }else{
            $message = "「".$status."」を投稿しようとしましたが失敗しました。<br />";
            echo $message;
            echo $response["errors"][0]["message"];               
            echo "<br /><br />";
            return array("error" => $message);
        }
    }


//============================================================
//基本的なAPIを叩くための関数
//============================================================
    function _setData($url, $value = array()){
		$this->OAuth_Consumer_build();//ここでHTTP_OAuth_Consumerを作り直し
        return json_decode($this->consumer->sendRequest($url, $value, "POST")->getBody(), true);
    }    

    function _getData($url){
		$this->OAuth_Consumer_build();//ここでHTTP_OAuth_Consumerを作り直し
        return json_decode($this->consumer->sendRequest($url, array(), "GET")->getBody(), true);
    }    

	function OAuth_Consumer_build(){
        $this->consumer = new HTTP_OAuth_Consumer($this->_consumer_key, $this->_consumer_secret);    
        $http_request = new HTTP_Request2();  
        $http_request->setConfig('ssl_verify_peer', false);  
        $consumer_request = new HTTP_OAuth_Consumer_Request;  
        $consumer_request->accept($http_request);  
        $this->consumer->accept($consumer_request);  
        $this->consumer->setToken($this->_access_token);  
        $this->consumer->setTokenSecret($this->_access_token_secret);
		return;                
	}

    function setUpdate($value){        
        $url = "https://api.twitter.com/1.1/statuses/update.json";
        return $this->_setData($url,$value);
    }            

    function getReplies($since_id = NULL){
        $url = "https://api.twitter.com/1.1/statuses/mentions_timeline.json?";
        if ($since_id) {
            $url .= 'since_id=' . $since_id ."&";
        }
        $url .= "count=100";
        $response = $this->_getData($url);
        if($response["errors"]){
            echo $response["errors"][0]["message"];               
        }                   
        return $response;
    }        

    function getFriendsTimeline($since_id = 0, $num = 100){
        $url = "https://api.twitter.com/1.1/statuses/home_timeline.json?";
        if ($since_id) {
            $url .= 'since_id=' . $since_id ."&";
        }        
        $url .= "count=" .$num ;
        $response = $this->_getData($url);
        if($response["errors"]){
            echo $response["errors"][0]["message"];               
        }                   
        return $response;
    }

    function followUser($id)
    {    
        $url = "https://api.twitter.com/1.1/friendships/create.json";
        $value = array("user_id"=>$id, "follow"=>"true");
        return $this->_setData($url,$value);
    }
    
    function getFriends($id = null)
    {
        $url = "https://api.twitter.com/1.1/friends/ids.json";
        $response = $this->_getData($url);
        if($response["errors"]){
            echo $response["errors"][0]["message"];               
        }                   
        return $response;
    }    

    function getFollowers()
    {
        $url = "https://api.twitter.com/1.1/followers/ids.json";
        $response = $this->_getData($url);
        if($response["errors"]){
            echo $response["errors"][0]["message"];               
        }                   
        return $response;
    }
        
    function checkApi($resources = "statuses"){
        $url = "https://api.twitter.com/1.1/application/rate_limit_status.json";
        if ($resources) {
            $url .= '?resources=' . $resources;
        }
        $response = $this->_getData($url);    
        var_dump($response);
    }    
}
?>