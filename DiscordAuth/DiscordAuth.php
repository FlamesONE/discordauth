<?php

class DiscordAuth
{
    /**
     * Api URL
     */
    public static $baseUrl = "https://discord.com";

    /**
     * Discord URL's
     */
    protected static $urls = [];

    /**
     * Not using var
     */
    protected static $debug = false;

    /**
     * @var string $red_url = Discord redirect site
     */
    protected static $red_url;

    /**
     * @var int $client_id = Client id, from Discord application
     */
    protected static $client_id;

    /**
     * @var string $client_secret = Client secret key from application.
     */
    protected static $client_secret;

    /**
     * @var bool $bot_token = Bot token, for guild auth
     */
    protected static $bot_token = null;

    /**
     * @var array $scopes = Custom identifity scopes
     */
    protected static $scopes = [];

    /**
     * @var array $debug_log = Debug log text
     */
    protected static $debug_log = [];

    /**
     * constructor
     */
    public function __construct( string $red_url, int $client_id, string $client_secret, string $bot_token = null, bool $debug = false )
    {
        # Basic check variables
        if( empty( $red_url ) )
            throw new Exception( "Redirect url is empty" );

        if( empty( $client_id ) )
            throw new Exception( "Client id is empty" );

        if( empty( $client_secret ) )
            throw new Exception( "Client secret is empty" );

        # Set to global
        self::$red_url          = $red_url;
        self::$client_id        = $client_id;
        self::$client_secret    = $client_secret;
        self::$urls             = [
            "token"     => self::$baseUrl . "/api/oauth2/token",
            "user"      => self::$baseUrl . "/api/users/@me",
            "guilds"    => self::$baseUrl . "/api/users/@me/guilds",
            "guilds_p"  => self::$baseUrl . "/api/guilds/%id",
            "connect"   => self::$baseUrl . "/api/users/@me/connections",
            "join_g"    => self::$baseUrl . "/api/guilds/%guildid/members/%user_id"
        ];

        $debug == true && self::$debug = $debug; // If debug true
        
        !empty( $bot_token ) && self::$bot_token = $bot_token; // Check bot token
    }

    /**
     * send cURL request
     */
    protected static function sendCurl( string $url, $data = [], bool $standart_header = true, array $headers = [], string $method = "POST", bool $decode = true )
    {
        self::$debug == true && self::addDebug( "Try to init cURL - $url" );

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        
        if( $method == "POST" )
            curl_setopt($curl, CURLOPT_POST, 1);

        $standart_header == true && $headers = [
            'Content-Type: application/x-www-form-urlencoded', 
            "Authorization: Bearer {$_SESSION['access_token']}"
        ];

        !empty( $headers ) && curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        if( !empty( $data ) )
        {
            if( is_array( $data ) )
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query( $data ) );
            else
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data );
        }

        $response = curl_exec($curl);
        curl_close($curl);
        
        return $decode == true ? json_decode($response, true) : $response;
    }

    /**
     * Generate hash state
     */
    public static function generateState( bool $session = true )
    {
        $state = bin2hex(openssl_random_pseudo_bytes(12));

        self::$debug == true && self::addDebug( "Generated state - $state" );
        
        # Save to session, if $session true
        $session == true && self::setSession( [ "state" => $state ] );

        return $state;
    }

    /**
     * Set custom scopes
     */
    public static function setScopes( array $scopes )
    {
        self::$scopes = $scopes;
    }

    /**
     * Get scopes
     */
    public static function getScopes( bool $array = false )
    {
        return ( $array == false ) ? implode( " ", self::$scopes ) : self::$scopes;
    }

    /**
     * Generate oauth URL
     */
    public static function generateURL()
    {
        return 'https://discordapp.com/oauth2/authorize?response_type=code&client_id=' . self::$client_id . '&redirect_uri=' . self::$red_url . '&scope=' . self::getScopes() . "&state=" . self::generateState();
    }

    /**
     * set to session
     */
    public static function setSession( array $data )
    {
        !isset( $_SESSION ) && session_start(); // Create session, if not exists

        if( sizeof( $data ) > 1 ) // If multi array
        {
            foreach( $data as $key => $val )
                $_SESSION[ $key ] = $val;
        }
        else
        {   
            $key = key( $data );
            $_SESSION[ $key ] = $data[ $key ];
        }
    }

    /**
     * debug array
     */
    protected static function addDebug( string $text )
    {
        if( self::$debug )
            array_push( self::$debug_log, $text );
    }

    /**
     * debug array
     */
    public static function getDebug()
    {
        return implode( "\n", self::$debug_log );
    }

    /**
     * Init request
     */
    public static function initRequest()
    {
        $code   = $_GET['code'];
        $state  = $_GET['state'];

        if( isset( $_SESSION["state"] ) && $state != $_SESSION )
            return;

        self::$debug == true && self::addDebug( "Try to init request, there core = $code and state = $state" );
        
        # Request data array
        $data   = [
            "client_id"         => self::$client_id,
            "client_secret"     => self::$client_secret,
            "grant_type"        => "authorization_code",
            "code"              => $code,
            "redirect_uri"      => self::$red_url
        ];

        $curl = self::sendCurl( self::getURL( "token" ), $data, false, ["Content-Type: application/x-www-form-urlencoded"] );

        self::setSession([
            "access_token" => $curl["access_token"]
        ]);

        self::initUser();
    }

    /**
     * get URL
     */
    protected static function getURL( string $key, array $params = [] )
    {
        if( !empty( self::$urls[ $key ] ) )
        {
            $string = self::$urls[ $key ]; // set to var

            if( !empty( $params ) )
            {
                foreach( $params as $key => $val )
                    str_replace( "%$key", $val, $string );
            }

            return $string;
        }
        return null;
    }

    /**
     * Init user
     */
    protected static function initUser()
    {
        $curl = self::sendCurl( self::getURL("user"), [], true, [], "GET" );

        self::setSession([
            "user" => $curl
        ]);
    }

    /**
     * Get user guilds
     */
    public static function getGuilds() : array
    {
        return self::sendCurl( self::getURL("guilds"), [], true, [], "GET" );
    }

    /**
     * Get current guild
     */
    public static function getGuild( int $id ) : array
    {
        return self::sendCurl( self::getURL("guilds_p", [
            "id" => $id
        ]), [], true, [], "GET" );
    }

    /**
     * get user connections
     */
    public static function getConnections()
    {
        return self::sendCurl( self::getURL("connect"), [], true, [], "GET" );
    }

    /**
     * get session data
     */
    public static function getSession( string $key = null )
    {
        if( isset( $_SESSION["user"] ) )
            return !empty( $key ) ? ( isset( $_SESSION["user"][ $key ] ) ? $_SESSION[ $key ] : null ) : $_SESSION["user"];

        return null;
    }

    /**
     * Join guild
     */
    public static function joinGuild( int $id )
    {
        if( empty( $id ) )
            throw new Exception( "Guild id empty" );

        if( empty( self::$bot_token ) )
            throw new Exception( "Bot token is empty" );

        $data = json_encode([
            "access_token" => self::getSession( "access_token" )
        ], true);

        $headers = [
            'Content-Type: application/json', 
            "Authorization: Bot " . self::$bot_token
        ];

        return self::sendCurl( self::getURL("join_g", [
            "guildid" => intval( $id ),
            "user_id" => self::getSession( "user_id" )
        ]), $data, false, $headers, "PUT" );
    }

    /**
     * session destroy
     */
    public static function sessionDestroy()
    {
        session_destroy();
    }

    /**
     * redirect
     */
    public static function redirect( string $path )
    {
        header("Location: $path");
    }
}