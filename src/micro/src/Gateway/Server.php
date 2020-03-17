<?php

namespace Mix\Micro\Gateway;

use Mix\Http\Message\Factory\StreamFactory;
use Mix\Http\Message\Response;
use Mix\Http\Message\ServerRequest;
use Mix\Http\Server\Server as HttpServer;
use Mix\Http\Server\HandlerInterface;
use Mix\Micro\Exception\NotFoundException;
use Mix\Micro\Gateway\Event\AccessEvent;
use Mix\Micro\RegistryInterface;
use Mix\Micro\ServiceInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Class Server
 * @package Mix\Micro\Gateway
 */
class Server implements HandlerInterface
{

    /**
     * @var int
     */
    public $port = 9595;

    /**
     * @var bool
     */
    public $reusePort = false;

    /**
     * @var string[]
     */
    public $proxies = [];

    /**
     * @var RegistryInterface
     */
    public $registry;

    /**
     * @var EventDispatcherInterface
     */
    public $dispatcher;

    /**
     * @var string
     */
    protected $host = '0.0.0.0';

    /**
     * @var bool
     */
    protected $ssl = false;

    /**
     * @var HttpServer
     */
    protected $httpServer;

    /**
     * @var ProxyInterface[][]
     */
    protected $proxyMap = [];

    /**
     * Server constructor.
     * @param int $port
     * @param bool $reusePort
     */
    public function __construct(int $port, bool $reusePort = false)
    {
        $this->port      = $port;
        $this->reusePort = $reusePort;
    }

    /**
     * Start
     * @throws \Swoole\Exception
     */
    public function start()
    {
        // 解析
        foreach ($this->proxies as $class) {
            $proxy                               = new $class();
            $this->proxyMap[$proxy->pattern()][] = $proxy;
        }
        // 启动
        $server = $this->httpServer = new HttpServer($this->host, $this->port, $this->ssl, $this->reusePort);
        $server->start($this);
    }

    /**
     * Handle HTTP
     * @param ServerRequest $request
     * @param Response $response
     */
    public function handleHTTP(ServerRequest $request, Response $response)
    {
        $microtime = static::microtime();
        $map       = $this->proxyMap;
        $path      = $request->getUri()->getPath();
        $pattern   = isset($map[$path]) ? $path : '/';
        foreach ($map[$pattern] ?? [] as $proxy) {
            try {
                $serivce = $proxy->service($this->registry, $request);
                $status  = $proxy->proxy($serivce, $request, $response);
                if ($status == 502) {
                    static::show502($response);
                }
                $event           = new AccessEvent();
                $event->time     = round((static::microtime() - $microtime) * 1000, 2);
                $event->status   = $status;
                $event->request  = $request;
                $event->response = $response;
                $event->service  = $serivce;
                $this->dispatch($event);
                return;
            } catch (NotFoundException $ex) {
            }
        }
        static::show404($response);
        $event           = new AccessEvent();
        $event->time     = round((static::microtime() - $microtime) * 1000, 2);
        $event->status   = 404;
        $event->request  = $request;
        $event->response = $response;
        $this->dispatch($event);
    }

    /**
     * Dispatch
     * @param object $event
     */
    protected function dispatch(object $event)
    {
        if (!isset($this->dispatcher)) {
            return;
        }
        $this->dispatcher->dispatch($event);
    }

    /**
     * 获取微秒时间
     * @return float
     */
    protected static function microtime()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    /**
     * 404 处理
     * @param Response $response
     * @return void
     */
    public static function show404(Response $response)
    {
        $content = '404 Not Found';
        $body    = (new StreamFactory())->createStream($content);
        $response
            ->withContentType('text/plain')
            ->withBody($body)
            ->withStatus(404)
            ->end();
    }

    /**
     * 502 处理,
     * @param Response $response
     * @return void
     */
    public static function show502(Response $response)
    {
        $content = '502 Bad Gateway';
        $body    = (new StreamFactory())->createStream($content);
        $response
            ->withContentType('text/plain')
            ->withBody($body)
            ->withStatus(502)
            ->end();
    }

    /**
     * Shutdown
     * @throws \Swoole\Exception
     */
    public function shutdown()
    {
        foreach ($this->proxyMap as $values) {
            foreach ($values as $proxy) {
                $proxy->close();
            }
        }
        $this->httpServer->shutdown();
    }

}
