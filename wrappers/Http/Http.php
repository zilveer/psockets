<?php

class Http extends Wrapper {
    private $hosts;
    private $buffers;
    private $components;

    public function init() {
        $this->hosts = array();
        $this->buffers = array();
        $this->components = array();

        $hosts = !empty($this->config['hosts']) ? $this->config['hosts'] : array();

        if ($hosts !== null) {
            foreach($hosts as $host => $components) {
                foreach ($components as $component) {
                    $this->hosts[$host] = $this->loadComponent($component, $host);
                }
            }
        }
    }

    public function loadComponent($component, $host) {
        $c = new $component($this->server);
        if ($c instanceof HttpComponent && !empty($c::$PATH)) {
            if ($this->server !== null) {
                $c->onLoad($this->server->ip, $this->server->port, $host);
            }
            $this->components[$c::$PATH] = $c;
            return $c;
        } else {
            $this->log->error("Failed to load component $component. It does not implement the Component interface.");
        }
        return null;
    }

    public function onConnect($con) {
        $this->buffers[$con->id] = '';
    }

    public function onDisconnect($con) {
        $this->buffers[$con->id] = null;// Free up the memory, cause a simple unset will not and we will have a memory leak
        unset($this->buffers[$con->id]);
    }

    public function onData($con, $data) {
        $buffer = $this->buffers[$con->id];
        $buffer .= $data;

        if (strpos($buffer, "\r\n\r\n") !== false) {
            $this->buffers[$con->id] = '';

            $lines = explode("\r\n", $buffer);
            array_pop($lines);
            $requestLine = array_shift($lines);

            if (preg_match('/^(\w+)\s(.*?)\sHTTP\/([\d\.]+)$/', $requestLine, $matches)) {
                array_shift($matches);
                list($method, $target, $version) = $matches;
                $method = strtoupper($method);
                $allowed_methods = array('GET');

                if (!in_array($method, $allowed_methods)) {
                    $con->send($this->getHardcodedError501($version));
                    return;
                }

                $path = '/';
                $query = array();

                if (preg_match('/([^\?]*)(?:\?(.*))?/', $target, $matches)) {
                    $path = $matches[1];

                    if (isset($matches[2])) {
                        parse_str($matches[2], $query);
                    }
                }

                $host = 'localhost';
                $headers = $cookies = array();
                foreach ($lines as $line) {
                    $parts = explode(':', $line);
                    $header = strtolower(array_shift($parts));
                    $value = trim(implode(':', $parts));
                    $headers[$header] = $value;

                    if ($header == 'cookie') {
                        $parts = explode("; ", $value);

                        foreach ($parts as $part) {
                            $cookieParts = explode("=", $part);
                            $cookieName = array_shift($cookieParts);
                            $cookieVal = implode("=", $cookieParts);
                            $cookies[$cookieName] = $cookieVal;
                        }
                    } else if ($header == 'host') {
                        $value = current(explode(':', $value));
                        if ($value == '127.0.0.1') {
                            $value = 'localhost';
                        }

                        $host = $value;
                    }
                }

                $req = new HttpRequest($method, $headers, $path, $query, $cookies);
                if (isset($this->hosts[$host])) {
                    $this->hosts[$host]->onRequest($con, $req);
                }
                //$resp = $this->processRequest($version, $method, $path, $query, $headers);

                //if ($resp) {
                //    $con->send($resp);
                //} else {
                //    $con->send($this->getHardcodedError500($version));
                //}

                //$con->close();
            }
        } else {
            $this->buffers[$con->id] = $buffer;
        }
    }

    public function onStop() {}

    private function getResponse($version, $resp) {
        $contentLength = strlen($resp);
        return "HTTP/$version 200 OK\r\nServer: psockets\r\nContent-Length: $contentLength\r\n\r\n$resp";
    }

    private function getHardcodedReponse200($version) {
        return "HTTP/$version 200 OK\r\nServer: psockets\r\nContent-Length: 2\r\n\r\nHi";
    }

    private function getHardcodedError500($version) {
        return "HTTP/$version 500 Internal Server Error\r\nServer: psockets\r\nContent-Length: 5\r\n\r\nOops!";
    }

    private function getHardcodedError501($version) {
        return "HTTP/$version 501 Not Implemented\r\nServer: psockets\r\nContent-Length: 5\r\n\r\nOops!";
    }

    private function processRequest($httpVersion, $method, $path, $query, $headers) {
        if ($query) {
            //$resp = "Result is: " . ((int)$query['a'] + (int)$query['b']);
            $resp = (int)$query['a'] + (int)$query['b'];
            return $this->getResponse($httpVersion, $resp);
        }

        return $this->getHardcodedReponse200($httpVersion);
    }
}
