<?
class restClient {
   protected $base = ''; // /< REST endpoint for the currency exchange
   protected $api_key; // /< API key that you created in the binance website member area
   protected $api_secret; // /< API secret that was given to you when you created the api key
   protected $proxyConf = null; // /< Used for story the proxy configuration
   public $httpDebug = false; // /< If you enable this, curl will output debugging information
   protected $transfered = 0; // /< This stores the amount of bytes transfered
   protected $requestCount = 0; // /< This stores the amount of API requests

   public function __construct(string $baseURL, string $api_key = '', string $api_secret = '', array $options = []) {
      $this->api_key = $api_key;
      $this->api_secret = $api_secret;
      $this->base = $baseURL;
   }

	public function httpRequest( string $url, string $method = "GET", $params = [], bool $signed = false ) {
      if( function_exists( 'curl_init' ) == false ) {
         throw new \Exception( "Sorry cURL is not installed!" );
      }

      //print_r($params);
      
      $ch = curl_init();
      curl_setopt( $ch, CURLOPT_VERBOSE, $this->httpDebug );

      if (is_array($params)) $query = http_build_query( $params, '', '&' );
      else $query = $params;
      
      // signed with params
      if( $signed == true ) {
         if( empty( $this->api_key ) )
            throw new \Exception( "signedRequest error: API Key not set!" );
         if( empty( $this->api_secret ) )
            throw new \Exception( "signedRequest error: API Secret not set!" );
         $base = $this->base;
         $ts = ( microtime( true ) * 1000 ) + $this->info[ 'timeOffset' ];
         $params[ 'timestamp' ] = number_format( $ts, 0, '.', '' );
         if( isset( $params[ 'wapi' ] ) ) {
            unset( $params[ 'wapi' ] );
            $base = $this->wapi;
         }
         $signature = hash_hmac( 'sha256', $query, $this->api_secret );
         $endpoint = $base.$url;

         if ($method == "POST") $query .= '&signature='.$signature;
         else $endpoint .= '?'.$query.'&signature='.$signature;
         curl_setopt( $ch, CURLOPT_URL, $endpoint );
         curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 
               'X-MBX-APIKEY: ' . $this->api_key 
         ) );
      }
      // params so buildquery string and append to url
      else if (is_array($params) && (count($params) > 0)) {
         curl_setopt( $ch, CURLOPT_URL, $this->base . $url . '?' . $query );
      }
      // no params so just the base url
      else {
         curl_setopt( $ch, CURLOPT_URL, $this->base . $url );
         curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 
               'X-MBX-APIKEY: ' . $this->api_key 
         ) );
      }
      curl_setopt( $ch, CURLOPT_USERAGENT, "User-Agent: Mozilla/4.0 (compatible; PHP Binance API)" );
      // Post and postfields
      if( $method == "POST" ) {
         curl_setopt( $ch, CURLOPT_POST, true );
         curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
      }
      // Delete Method
      if( $method == "DELETE" ) {
         curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method );
      }
      // proxy settings
      if( is_array( $this->proxyConf ) ) {
         curl_setopt( $ch, CURLOPT_PROXY, $this->getProxyUriString() );
         if( isset( $this->proxyConf[ 'user' ] ) && isset( $this->proxyConf[ 'pass' ] ) ) {
            curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $this->proxyConf[ 'user' ] . ':' . $this->proxyConf[ 'pass' ] );
         }
      }
      curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
      // headers will proceed the output, json_decode will fail below
      curl_setopt( $ch, CURLOPT_HEADER, false );
      curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
      curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );
      curl_setopt( $ch, CURLINFO_HEADER_OUT, true); //DEV
      $output = curl_exec( $ch );
      // Check if any error occurred
      if( curl_errno( $ch ) > 0 ) {
         echo 'Curl error: ' . curl_error( $ch ) . "\n";
         return [];
      }
      $json = json_decode($output, true);
      curl_close($ch);
      $len = strlen($output);
      if ($len == 0) {
        throw new Exception("Empty response data", 1); 
      }
      $this->transfered += strlen($output);
      $this->requestCount++;
      return $json;
   }	
}
?>