<?php
/**
 * (c) 2007-2009 Chris Maciejewski
 * 
 * Permission is hereby granted, free of charge, to any person obtaining 
 * a copy of this software and associated documentation files 
 * (the "Software"), to deal in the Software without restriction, 
 * including without limitation the rights to use, copy, modify, merge,
 * publish, distribute, sublicense, and/or sell copies of the Software, 
 * and to permit persons to whom the Software is furnished to do so, 
 * subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included 
 * in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS 
 * OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, 
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL 
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER 
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING 
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER 
 * DEALINGS IN THE SOFTWARE.
 * 
 */

/**
 * PHP OpenSIPs MI class
 * 
 * @ingroup  API
 * @author Chris Maciejewski <chris@level7systems.co.uk>
 * 
 * @version    SVN: $Id: PhpSIP.class.php 24 2009-11-25 11:39:57Z level7systems $
 */
require_once 'PhpOpenSIPs.Exception.php';

class PhpOpenSIPs
{
  private $debug = false;
  
  /**
   * XMLRPC host
   */
  private $xmlrpc_host = '127.0.0.1';
  
  /**
   * XMLRPC port
   */
  private $xmlrpc_port = 8000;
  
 
  /**
   * Final Response timer (in seconds)
   */
  private $fr_timer = 7;
  
  /**
   * Allowed methods array
   */
  private $allowed_methods = array(
    "CANCEL","NOTIFY", "INVITE","BYE","REFER","OPTIONS","SUBSCRIBE","MESSAGE"
  );
  
  /**
   * Dialog established
   */
  private $dialog = false;
  
  /**
   * SIP socket
   */
  private $sip_socket;
  
  /**
   * The opened socket to MI
   */
  private $socket;
  
  /**
   * Call ID
   */
  private $call_id;
  
  /**
   * Contact
   */
  private $contact;
  
  /**
   * Request URI
   */
  private $uri;
  
  /**
   * Request host
   */
  private $host;
  
  /**
   * Request port
   */
  private $port = 5060;
  
  /**
   * Outboud SIP proxy
   */
  private $proxy;
  
  /**
   * Method
   */
  private $method;
  
  /**
   * Auth username
   */
  private $username;
  
  /**
   * Auth password
   */
  private $password;
  
  /**
   * To
   */
  private $to;
  
  /**
   * From
   */
  private $from;
  
  /**
   * From User
   */
  private $from_user;

  /**
   * Via tag
   */
  private $via;
  
  /**
   * Content type
   */
  private $content_type;
  
  /**
   * Body
   */
  private $body = null;
  
  /**
   * Received Response
   */
  private $response; // whole response body
  private $res_code;
  private $res_contact;
  private $res_cseq_method;
  private $res_cseq_number;

  /**
   * Received Request
   */
  private $req_method;
  private $req_cseq_method;
  private $req_cseq_number;
  private $req_contact;
  
  /**
   * Authentication
   */
  private $auth;
  
  /**
   * Routes
   */
  private $routes = array();
  
  /**
   * Request vias
   */
  private $request_via = array();
  
  /**
   * Additional headers
   */
  private $extra_headers = array();
  
  /**
   * Constructor
   * 
   * @param $src_ip Ip address to bind (optional)
   */
  public function __construct($src_ip = null)
  {
    if (!function_exists('fsockopen'))
    {
      throw new PhpOpenSIPsException("fsockopen() function missing.");
    }
    
    if (!$src_ip)
    {
      // running in a web server
      if (isset($_SERVER['SERVER_ADDR']))
      {
        $src_ip = $_SERVER['SERVER_ADDR'];
      }
      // running from command line
      else
      {
        $addr = gethostbynamel(php_uname('n'));
        
        if (!is_array($addr) || !isset($addr[0]) || substr($addr[0],0,3) == '127')
        {
          throw new PhpOpenSIPsException("Failed to obtain IP address to bind. Please set bind address manualy.");
        }
      
        $src_ip = $addr[0];
      }
    }
    
    $this->src_ip = $src_ip;
    
    $this->createSocket();
  }
  
  /**
   * Destructor
   */
  public function __destruct()
  {
    $this->closeSocket();
  }
  
  /**
   * Sets debuggin ON/OFF
   * 
   * @param bool $status
   */
  public function setDebug($status = false)
  {
    $this->debug = $status;
  }
  
  /**
   * Adds aditional header
   * 
   * @param string $header
   */
  public function addHeader($header)
  {
    $this->extra_headers[] = $header;
  }
  
  /**
   * Sets SIP socket
   * 
   * @param string $socket
   */
  public function setSocket($socket)
  {
    $this->sip_socket = $socket;
  }
  
  /**
   * Sets From header
   * 
   * @param string $from
   */
  public function setFrom($from)
  {
    if (preg_match('/<.*>$/',$from))
    {
      $this->from = $from;
    }
    else
    {
      $this->from = '<'.$from.'>';
    }
    
    $m = array();
    if (!preg_match('/sip:(.*)@/i',$this->from,$m))
    {
      throw new PhpOpenSIPsException('Failed to parse From username.');
    }
    
    $this->from_user = $m[1];
  }
  
  /**
   * Sets method
   * 
   * @param string $method
   */
  public function setMethod($method)
  {
    if (!in_array($method,$this->allowed_methods))
    {
      throw new PhpOpenSIPsException('Invalid method.');
    }
    
    $this->method = $method;
    
    if ($method == 'INVITE')
    {
      $body = "v=0\r\n";
      $body.= "o=click2dial 0 0 IN IP4 ".$this->src_ip."\r\n";
      $body.= "s=click2dial call\r\n";
      $body.= "c=IN IP4 ".$this->src_ip."\r\n";
      $body.= "t=0 0\r\n";
      $body.= "m=audio 8000 RTP/AVP 0 8 18 3 4 97 98\r\n";
      $body.= "a=rtpmap:0 PCMU/8000\r\n";
      $body.= "a=rtpmap:18 G729/8000\r\n";
      $body.= "a=rtpmap:97 ilbc/8000\r\n";
      $body.= "a=rtpmap:98 speex/8000\r\n";
      
      $this->body = $body;
      
      $this->setContentType(null);
    }
    
    if ($method == 'REFER')
    {
      $this->setBody('');
    }
    
    if ($method == 'CANCEL')
    {
      $this->setBody('');
      $this->setContentType(null);
    }
    
    if ($method == 'MESSAGE')
    {
      $this->setContentType(null);
    }
  }
  
  /**
   * Sets SIP Proxy
   * 
   * @param $proxy
   */
  public function setProxy($proxy)
  {
    $this->proxy = $proxy;
  }
  
  /**
   * Sets request URI
   *
   * @param string $uri
   */
  public function setUri($uri)
  {
    if (strpos($uri,'sip:') === false)
    {
      throw new PhpOpenSIPsException("Only sip: URI supported.");
    }
    
    $this->uri = $uri;
    $this->to = '<'.$uri.'>';
    
    if ($this->proxy)
    {
      if (strpos($this->proxy,':'))
      {
        $temp = explode(":",$this->proxy);
        
        $this->host = $temp[0];
        $this->port = $temp[1];
      }
      else
      {
        $this->host = $this->proxy;
      }
    }
    else
    {
      $url = str_replace("sip:","sip://",substr($uri,0,strpos(";")));
      
      if (!$url = @parse_url($url))
      {
        throw new PhpOpenSIPsException("Failed to parse URI '$url'.");
      }
      
      $this->host = $url['host'];
      
      if (isset($url['port']))
      {
        $this->port = $url['port'];
      }
    }
  }
  
  /**
   * Sets username
   *
   * @param string $username
   */
  public function setUsername($username)
  {
    $this->username = $username;
  }
  
  /**
   * Sets password
   *
   * @param string $password
   */
  public function setPassword($password)
  {
    $this->password = $password;
  }
  
  /**
   * Sends SIP request
   * 
   * @return string Reply 
   */
  public function send()
  {
    if (!$this->from)
    {
      throw new PhpOpenSIPsException('Missing From.');
    }
    
    if (!$this->method)
    {
      throw new PhpOpenSIPsException('Missing Method.');
    }
    
    if (!$this->uri)
    {
      throw new PhpOpenSIPsException('Missing URI.');
    }
    
    $data = $this->formatRequest();
    
    $this->sendData($data);
    
    $this->readResponse();
    
    if ($this->method == 'CANCEL' && $this->res_code == '200')
    {
      $i = 0;
      while (substr($this->res_code,0,1) != '4' && $i < 2)
      {
        $this->readResponse();
        $i++;
      }
    }
    
    if ($this->res_code == '407')
    {
      $this->cseq++;
      
      $this->auth();
      
      $data = $this->formatRequest();
      
      $this->sendData($data);
      
      $this->readResponse();
    }
    
    if ($this->res_code == '401')
    {
      $this->cseq++;
      
      $this->authWWW();
      
      $data = $this->formatRequest();
      
      $this->sendData($data);
      
      $this->readResponse();
    }
    
    if (substr($this->res_code,0,1) == '1')
    {
      $i = 0;
      while (substr($this->res_code,0,1) == '1' && $i < 4)
      {
        $this->readResponse();
        $i++;
      }
    }
    
    $this->extra_headers = array();
    $this->cseq++;
    
    return $this->res_code;
  }
  
  /**
   * Sends data
   */
  private function sendData($request)
  {
    $xml = xmlrpc_encode_request("t_uac_dlg", $request);
    
    $query = "POST /RPC2 HTTP/1.0\nUser-Agent: php-opensips\nHost: ".$this->xmlrpc_host."\nContent-Type: text/xml\nConnection: keep-alive\nContent-Length: ".strlen($xml)."\n\n".$xml."\n";
    
    if (!fputs($this->socket, $query, strlen($query)))
    {
      throw new PhpOpenSIPsException ("Failed to write to socket");
    }

    if ($this->debug)
    { 
      echo "--> ".$request[0]." ".$request[1]."\n";
    }
  }
  
  /**
   * Listen for request
   * 
   * @todo This needs to be improved
   */
  public function listen($method)
  {
    $i = 0;
    while ($this->req_method != $method)
    {
      $this->readResponse(); 
      
      $i++;
      
      if ($i > 5)
      {
        throw new PhpOpenSIPsException("Unexpected request ".$this->req_method."received.");
      }
    }
  }
  
  /**
   * Reads response
   */
  private function readResponse()
  {
    $data = '';

    while (!feof($this->socket))
    {
      $chunk = fgets($this->socket,4096);

      $data.= $chunk;

      if (substr($chunk,0,17) == "</methodResponse>")
      {
        break;
      }
    }
    
    $temp = explode("\r\n\r\n",$data,2);
    
    if (count($temp) != 2)
    {
      throw new PhpOpenSIPsException("Failed to parse reply.");
    }
    
    $this->response = xmlrpc_decode($temp[1]);
    
    if (!is_string($this->response))
    {
      throw new PhpOpenSIPsException("Failed to parse XMLRPC reply.");
    }
    
    if ($this->debug)
    {
      $temp = explode("\n",$this->response);
      
      echo "<-- ".$temp[0]."\n";
    }
    
    // Response
    $result = array();
    if (preg_match('/^([0-9]{3})/',$this->response,$result))
    {
      $this->res_code = trim($result[1]);
      
      $res_class = substr($this->res_code,0,1);
      if ($res_class == '1' || $res_class == '2')
      {
        $this->dialog = true;
      }
      
      $this->parseResponse();
    }
    // Request
    else
    {
      $this->parseRequest();
    }
  }
  
  /**
   * Parse Response
   */
  private function parseResponse()
  {
    // To tag
    $result = array();
    if (preg_match('/^To: .*;tag=(.*)$/im',$this->response,$result))
    {
      $this->to_tag = trim($result[1]);
    }
    
    // Route
    $result = array();
    if (preg_match_all('/^Record-Route: (.*)$/im',$this->response,$result))
    {
      foreach ($result[1] as $route)
      {
        if (!in_array(trim($route),$this->routes))
        {
          $this->routes[] = trim($route);
        }
      }
    }
    
    // Request via
    $result = array();
    $this->request_via = array();
    if (preg_match_all('/^Via: (.*)$/im',$this->response,$result))
    {
      foreach ($result[1] as $via)
      {
        $this->request_via[] = trim($via);
      }
    }
    
    // Response contact
    $result = array();
    if (preg_match('/^Contact:.*<(.*)>/im',$this->response,$result))
    {
      $this->res_contact = trim($result[1]);
      
      $semicolon = strpos($this->res_contact,";");
      
      if ($semicolon !== false)
      {
        $this->res_contact = substr($this->res_contact,0,$semicolon);
      }
    }
    
    // Response CSeq method
    $result = array();
    if (preg_match('/^CSeq: [0-9]+ (.*)$/im',$this->response,$result))
    {
      $this->res_cseq_method = trim($result[1]);
    }
    
    // ACK 2XX-6XX - only invites - RFC3261 17.1.2.1
    if ($this->res_cseq_method == 'INVITE' && in_array(substr($this->res_code,0,1),array('2','3','4','5','6')))
    {
      $this->ack();
    }
    
    return $this->res_code;
  }
  
  /**
   * Parse Request
   */
  private function parseRequest()
  {
    $temp = explode("\r\n",$this->response);
    $temp = explode(" ",$temp[0]);
    $this->req_method = trim($temp[0]);
    
    // Route
    $result = array();
    if (preg_match_all('/^Record-Route: (.*)$/im',$this->response,$result))
    {
      foreach ($result[1] as $route)
      {
        if (!in_array(trim($route),$this->routes))
        {
          $this->routes[] = trim($route);
        }
      }
    }
    
    // Request via
    $result = array();
    $this->request_via = array();
    if (preg_match_all('/^Via: (.*)$/im',$this->response,$result))
    {
      foreach ($result[1] as $via)
      {
        $this->request_via[] = trim($via);
      }
    }
    
    // Method contact
    $result = array();
    if (preg_match('/^Contact: <(.*)>/im',$this->response,$result))
    {
      $this->req_contact = trim($result[1]);
      
      $semicolon = strpos($this->res_contact,";");
      
      if ($semicolon !== false)
      {
        $this->res_contact = substr($this->res_contact,0,$semicolon);
      }
    }
    
    // Response CSeq method
    if (preg_match('/^CSeq: [0-9]+ (.*)$/im',$this->response,$result))
    {
      $this->req_cseq_method = trim($result[1]);
    }
    
    // Response CSeq number
    if (preg_match('/^CSeq: ([0-9]+) .*$/im',$this->response,$result))
    {
      $this->req_cseq_number = trim($result[1]);
    }
  }
  
  /**
   * Send Response
   * 
   * @param int $code     Response code
   * @param string $text  Response text
   */
  public function reply($code,$text)
  {
    $r = 'SIP/2.0 '.$code.' '.$text."\r\n";
    // Via
    foreach ($this->request_via as $via)
    {
      $r.= 'Via: '.$via."\r\n";
    }
    // From
    $r.= 'From: '.$this->from.';tag='.$this->to_tag."\r\n";
    // To
    $r.= 'To: '.$this->to.';tag='.$this->from_tag."\r\n";
    // Call-ID
    $r.= 'Call-ID: '.$this->call_id."\r\n";
    //CSeq
    $r.= 'CSeq: '.$this->req_cseq_number.' '.$this->req_cseq_method."\r\n";
    // Max-Forwards
    $r.= 'Max-Forwards: 70'."\r\n";
    // Content-Length
    $r.= 'Content-Length: 0'."\r\n";
    $r.= "\r\n";
    
    $this->sendData($r);
  }
  
  /**
   * ACK
   */
  private function ack()
  {
    if ($this->res_cseq_method == 'INVITE' && $this->res_code == '200')
    {
      $a = 'ACK '.$this->res_contact.' SIP/2.0'."\r\n";
    }
    else
    {
      $a = 'ACK '.$this->uri.' SIP/2.0'."\r\n";
    }
    // Via
    $a.= 'Via: '.$this->via."\r\n";
    // Route
    if ($this->routes)
    {
      foreach ($this->routes as $route)
      {
        $a.= 'Route: '.$route."\r\n";
      }
    }
    // From
    if (!$this->from_tag) $this->setFromTag();
    $a.= 'From: '.$this->from.';tag='.$this->from_tag."\r\n";
    // To
    if ($this->to_tag)
      $a.= 'To: '.$this->to.';tag='.$this->to_tag."\r\n";
    else
      $a.= 'To: '.$this->to."\r\n";
    // Call-ID
    if (!$this->call_id) $this->setCallId();
    $a.= 'Call-ID: '.$this->call_id."\r\n";
    //CSeq
    $a.= 'CSeq: '.$this->cseq.' ACK'."\r\n";
    // Authentication
    if ($this->res_code == '200' && $this->auth)
    {
      $a.= 'Proxy-Authorization: '.$this->auth."\r\n";
    }
    // Max-Forwards
    $a.= 'Max-Forwards: 70'."\r\n";
    // Content-Length
    $a.= 'Content-Length: 0'."\r\n";
    $a.= "\r\n";
    
    $this->sendData($a);
  }
  
  /**
   * Formats SIP request
   * 
   * @return string
   */
  private function formatRequest()
  {
    // method - request method
    // RURI - request SIP URI
    // NEXT HOP - next hop SIP URI (OBP); use “.” if no value.
    // socket - local socket to be used for sending the request; use “.” if no value.
    // headers - set of additional headers to be added to the request; at least “From” and “To” headers must be specify)
    // body - (optional, may not be present) request body (if present, requires the “Content-Type” and “Content-length” headers) 
    
    $request = array();
    $request[] = $this->method;
    $request[] = $this->uri;
    $request[] = ".";
    
    if ($this->sip_socket)
    {
      $request[] = $this->sip_socket;
    }
    else
    {
      $request[] = ".";
    }
    
    $headers = array();
    
    // Route
    if ($this->method != 'CANCEL' && $this->routes)
    {
      foreach ($this->routes as $route)
      {
        $headers[] = 'Route: '.$route;
      }
    }
    
    $headers[] = "From: ".$this->from;
    $headers[] = "To: ".$this->to;
    
    // Authentication
    if ($this->auth)
    {
      $headers[] = $this->auth;
      $this->auth = null;
    }

    // Content-Type
    if ($this->content_type)
    {
      $headers[] = 'Content-Type: '.$this->content_type;
    }
    
    // Max-Forwards
    $headers[] = 'Max-Forwards: 70';
    
    // Additional header
    foreach ($this->extra_headers as $header)
    {
      $headers[] = $header;
    }
    
    // Call-ID
    if (!$this->call_id) $this->setCallId();
    $headers[] = 'Call-ID: '.$this->call_id."\r\n";
    
    $request[] = implode("\r\n",$headers)."\r\n";
    
    if ($this->body)
    {
      $request[] = $this->body;
    }
    
    return $request;
  }
  
  /**
   * Sets body
   */
  public function setBody($body)
  {
    $this->body = $body;
  }
  
  /**
   * Sets Content Type
   */
  public function setContentType($content_type = null)
  {
    if ($content_type !== null)
    {
      $this->content_type = $content_type;
    }
    else
    {
      switch ($this->method)
      {
        case 'INVITE':
          $this->content_type = 'application/sdp';
          break;
        case 'MESSAGE':
          $this->content_type = 'text/html; charset=utf-8';
          break;
        default:
          $this->content_type = null;
      }
    }
  }
  
 
  /**
   * Sets from tag
   */
  private function setFromTag()
  { 
    $this->from_tag = rand(10000,99999);
  }
  
  /**
   * Sets call id
   */
  private function setCallId()
  {
    $this->call_id = md5(uniqid()).'@'.$this->src_ip;
  }
  
  /**
   * Gets value of the header from the previous request
   * 
   * @param string $name Header name
   * 
   * @return string or false
   */
  public function getHeader($name)
  {
    if (preg_match('/^'.$name.': (.*)$/m',$this->response,$result))
    {
      return trim($result[1]);
    }
    else
    {
      return false;
    }
  }
  
  /**
   * Calculates Digest authentication response
   * 
   */
  private function auth()
  {
    if (!$this->username)
    {
      throw new PhpOpenSIPsException("Missing username");
    }
    
    if (!$this->password)
    {
      throw new PhpOpenSIPsException("Missing password");
    }
    
    // realm
    $result = array();
    if (!preg_match('/^Proxy-Authenticate: .* realm="(.*)"/imU',$this->response, $result))
    {
      throw new PhpOpenSIPsException("Can't find realm in proxy-auth");
    }
    
    $realm = $result[1];
    
    // nonce
    $result = array();
    if (!preg_match('/^Proxy-Authenticate: .* nonce="(.*)"/imU',$this->response, $result))
    {
      throw new PhpOpenSIPsException("Can't find nonce in proxy-auth");
    }
    
    $nonce = $result[1];
    
    $ha1 = md5($this->username.':'.$realm.':'.$this->password);
    $ha2 = md5($this->method.':'.$this->uri);
    
    $res = md5($ha1.':'.$nonce.':'.$ha2);
    
    $this->auth = 'Proxy-Authorization: Digest username="'.$this->username.'", realm="'.$realm.'", nonce="'.$nonce.'", uri="'.$this->uri.'", response="'.$res.'", algorithm=MD5';
  }
  
  /**
   * Calculates WWW authorization response
   * 
   */
  private function authWWW()
  {
    if (!$this->username)
    {
      throw new PhpOpenSIPsException("Missing auth username");
    }
    
    if (!$this->password)
    {
      throw new PhpOpenSIPsException("Missing auth password");
    }
    
    $qop_present = false;
    if (strpos($this->response,'qop=') !== false)
    {
      $qop_present = true;
      
      // we can only do qop="auth"
      if  (strpos($this->response,'qop="auth"') === false)
      {
        throw new PhpOpenSIPsException('Only qop="auth" digest authentication supported.');
      }
    }
    
    // realm
    $result = array();
    if (!preg_match('/^WWW-Authenticate: .* realm="(.*)"/imU',$this->response, $result))
    {
      throw new PhpOpenSIPsException("Can't find realm in www-auth");
    }
    
    $realm = $result[1];
    
    // nonce
    $result = array();
    if (!preg_match('/^WWW-Authenticate: .* nonce="(.*)"/imU',$this->response, $result))
    {
      throw new PhpOpenSIPsException("Can't find nonce in www-auth");
    }
    
    $nonce = $result[1];
    
    $ha1 = md5($this->username.':'.$realm.':'.$this->password);
    $ha2 = md5($this->method.':'.$this->uri);
    
    if ($qop_present)
    {
      $cnonce = md5(time());
      
      $res = md5($ha1.':'.$nonce.':00000001:'.$cnonce.':auth:'.$ha2);
    }
    else
    {
      $res = md5($ha1.':'.$nonce.':'.$ha2);
    }
    
    $this->auth = 'Authorization: Digest username="'.$this->username.'", realm="'.$realm.'", nonce="'.$nonce.'", uri="'.$this->uri.'", response="'.$res.'", algorithm=MD5';
    
    if ($qop_present)
    {
      $this->auth.= ', qop="auth", nc="00000001", cnonce="'.$cnonce.'"';
    }
  }
  
  /**
   * Create network socket
   *
   * @return bool True on success
   */
  private function createSocket()
  {
    $errno = 0;
    $errstr = '';
    
    if (!$this->socket = @fsockopen($this->xmlrpc_host, $this->xmlrpc_port, &$errno, &$errstr))
    {
      throw new PhpOpenSIPsException($errno ." - ". $errstr);
    }
    
  }
  
  /**
   * Close the connection
   *
   * @return bool True on success
   */
  private function closeSocket()
  {
    fclose($this->socket);
  }
  
  /**
   * Resets callid, to/from tags etc.
   * 
   */
  public function newCall()
  {
    $this->cseq = 20;
    $this->call_id = null;
    $this->to_tag = null;;
    $this->from_tag = null;;
    
    /**
     * Body
     */
    $this->body = null;
    
    /**
     * Received Response
     */
    $this->response = null;
    $this->res_code = null;
    $this->res_contact = null;
    $this->res_cseq_method = null;
    $this->res_cseq_number = null;

    /**
     * Received Request
     */
    $this->req_method = null;
    $this->req_cseq_method = null;
    $this->req_cseq_number = null;
    $this->req_contact = null;
    
    $this->routes = array();
    $this->request_via = array();
  }
}

?>
