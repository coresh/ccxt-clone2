<?php

namespace ccxtpro;

// PLEASE DO NOT EDIT THIS FILE, IT IS GENERATED AND WILL BE OVERWRITTEN:
// https://github.com/ccxt/ccxt/blob/master/CONTRIBUTING.md#how-to-contribute-code

use Exception; // a common import

class ftx extends \ccxt\ftx {

    use ClientTrait;

    public function describe() {
        return $this->deep_extend(parent::describe (), array(
            'has' => array(
                'ws' => true,
                'watchOrderBook' => true,
                'watchTicker' => true,
                'watchTrades' => true,
                'watchOHLCV' => false, // missing on the exchange side
                'watchBalance' => false, // missing on the exchange side
                'watchOrders' => false, // not implemented yet
                'watchMyTrades' => false, // not implemented yet
            ),
            'urls' => array(
                'api' => array(
                    'ws' => 'wss://ftx.com/ws',
                ),
            ),
            'options' => array(
                'tradesLimit' => 1000,
            ),
            'streaming' => array(
                // ftx does not support built-in ws protocol-level ping-pong
                // instead it requires a custom text-based ping-pong
                'ping' => array($this, 'ping'),
                'keepAlive' => 15000,
            ),
        ));
    }

    public function watch_public($symbol, $channel, $params = array ()) {
        $this->load_markets();
        $market = $this->market($symbol);
        $marketId = $market['id'];
        $url = $this->urls['api']['ws'];
        $request = array(
            'op' => 'subscribe',
            'channel' => $channel,
            'market' => $marketId,
        );
        $messageHash = $channel . ':' . $marketId;
        return $this->watch($url, $messageHash, $request, $messageHash);
    }

    public function watch_ticker($symbol, $params = array ()) {
        return $this->watch_public($symbol, 'ticker');
    }

    public function watch_trades($symbol, $since = null, $limit = null, $params = array ()) {
        $future = $this->watch_public($symbol, 'trades');
        return $this->after($future, array($this, 'filter_by_since_limit'), $since, $limit, true);
    }

    public function watch_order_book($symbol, $limit = null, $params = array ()) {
        $future = $this->watch_public($symbol, 'orderbook');
        return $this->after($future, array($this, 'limit_order_book'), $symbol, $limit, $params);
    }

    public function sign_message($client, $messageHash, $message) {
        return $message;
    }

    public function handle_partial($client, $message) {
        $methods = array(
            'orderbook' => array($this, 'handle_order_book_snapshot'),
        );
        $methodName = $this->safe_string($message, 'channel');
        $method = $this->safe_value($methods, $methodName);
        if ($method) {
            $method($client, $message);
        }
    }

    public function handle_update($client, $message) {
        $methods = array(
            'trades' => array($this, 'handle_trades'),
            'ticker' => array($this, 'handle_ticker'),
            'orderbook' => array($this, 'handle_order_book_update'),
        );
        $methodName = $this->safe_string($message, 'channel');
        $method = $this->safe_value($methods, $methodName);
        if ($method) {
            $method($client, $message);
        }
    }

    public function handle_message($client, $message) {
        $methods = array(
            // ftx API docs say that all tickers and trades will be "partial"
            // however, in fact those are "update"
            // therefore we don't need to parse the "partial" update
            // since it is only used for orderbooks...
            // uncomment to fix if this is wrong
            // 'partial' => array($this, 'handle_partial'),
            'partial' => array($this, 'handle_order_book_snapshot'),
            'update' => array($this, 'handle_update'),
            'subscribed' => array($this, 'handle_subscription_status'),
            'unsubscribed' => array($this, 'handle_unsubscription_status'),
            'info' => array($this, 'handle_info'),
            'error' => array($this, 'handle_error'),
            'pong' => array($this, 'handle_pong'),
        );
        $methodName = $this->safe_string($message, 'type');
        $method = $this->safe_value($methods, $methodName);
        if ($method) {
            $method($client, $message);
        }
    }

    public function get_message_hash($message) {
        $channel = $this->safe_string($message, 'channel');
        $marketId = $this->safe_string($message, 'market');
        return $channel . ':' . $marketId;
    }

    public function handle_ticker($client, $message) {
        //
        //     {
        //         channel => 'ticker',
        //         $market => 'BTC/USD',
        //         type => 'update',
        //         $data => {
        //             bid => 6652,
        //             ask => 6653,
        //             bidSize => 17.6608,
        //             askSize => 18.1869,
        //             last => 6655,
        //             time => 1585787827.3118029
        //         }
        //     }
        //
        $data = $this->safe_value($message, 'data', array());
        $marketId = $this->safe_string($message, 'market');
        if (is_array($this->markets_by_id) && array_key_exists($marketId, $this->markets_by_id)) {
            $market = $this->markets_by_id[$marketId];
            $ticker = $this->parse_ticker($data, $market);
            $symbol = $ticker['symbol'];
            $this->tickers[$symbol] = $ticker;
            $messageHash = $this->get_message_hash($message);
            $client->resolve ($ticker, $messageHash);
        }
        return $message;
    }

    public function handle_order_book_snapshot($client, $message) {
        //
        //     {
        //         channel => "$orderbook",
        //         $market => "BTC/USD",
        //         type => "partial",
        //         $data => {
        //             time => 1585812237.6300597,
        //             $checksum => 2028058404,
        //             bids => [
        //                 [6655.5, 21.23],
        //                 [6655, 41.0165],
        //                 [6652.5, 15.1985],
        //             ],
        //             asks => [
        //                 [6658, 48.8094],
        //                 [6659.5, 15.6184],
        //                 [6660, 16.7178],
        //             ],
        //             action => "partial"
        //         }
        //     }
        //
        $data = $this->safe_value($message, 'data', array());
        $marketId = $this->safe_string($message, 'market');
        if (is_array($this->markets_by_id) && array_key_exists($marketId, $this->markets_by_id)) {
            $market = $this->markets_by_id[$marketId];
            $symbol = $market['symbol'];
            $options = $this->safe_value($this->options, 'watchOrderBook', array());
            $limit = $this->safe_integer($options, 'limit', 400);
            $orderbook = $this->order_book(array(), $limit);
            $this->orderbooks[$symbol] = $orderbook;
            $timestamp = $this->safe_timestamp($data, 'time');
            $snapshot = $this->parse_order_book($data, $timestamp);
            $orderbook->reset ($snapshot);
            // $checksum = $this->safe_string($data, 'checksum');
            // todo => $this->checkOrderBookChecksum ($client, $orderbook, $checksum);
            $this->orderbooks[$symbol] = $orderbook;
            $messageHash = $this->get_message_hash($message);
            $client->resolve ($orderbook, $messageHash);
        }
    }

    public function handle_delta($bookside, $delta) {
        $price = $this->safe_float($delta, 0);
        $amount = $this->safe_float($delta, 1);
        $bookside->store ($price, $amount);
    }

    public function handle_deltas($bookside, $deltas) {
        for ($i = 0; $i < count($deltas); $i++) {
            $this->handle_delta($bookside, $deltas[$i]);
        }
    }

    public function handle_order_book_update($client, $message) {
        //
        //     {
        //         channel => "$orderbook",
        //         $market => "BTC/USD",
        //         type => "update",
        //         $data => {
        //             time => 1585812417.4673214,
        //             $checksum => 2215307596,
        //             bids => [[6668, 21.4066], [6669, 25.8738], [4498, 0]],
        //             asks => array(),
        //             action => "update"
        //         }
        //     }
        //
        $data = $this->safe_value($message, 'data', array());
        $marketId = $this->safe_string($message, 'market');
        if (is_array($this->markets_by_id) && array_key_exists($marketId, $this->markets_by_id)) {
            $market = $this->markets_by_id[$marketId];
            $symbol = $market['symbol'];
            $orderbook = $this->orderbooks[$symbol];
            $this->handle_deltas($orderbook['asks'], $this->safe_value($data, 'asks', array()));
            $this->handle_deltas($orderbook['bids'], $this->safe_value($data, 'bids', array()));
            // $orderbook['nonce'] = u;
            $timestamp = $this->safe_timestamp($data, 'time');
            $orderbook['timestamp'] = $timestamp;
            $orderbook['datetime'] = $this->iso8601($timestamp);
            // $checksum = $this->safe_string($data, 'checksum');
            // todo => $this->checkOrderBookChecksum ($client, $orderbook, $checksum);
            $this->orderbooks[$symbol] = $orderbook;
            $messageHash = $this->get_message_hash($message);
            $client->resolve ($orderbook, $messageHash);
        }
    }

    public function handle_trades($client, $message) {
        //
        //     {
        //         channel =>   "$trades",
        //         $market =>   "BTC-PERP",
        //         type =>   "update",
        //         $data => array(
        //             {
        //                 id =>  33517246,
        //                 price =>  6661.5,
        //                 size =>  2.3137,
        //                 side => "sell",
        //                 liquidation =>  false,
        //                 time => "2020-04-02T07:45:12.011352+00:00"
        //             }
        //         )
        //     }
        //
        $data = $this->safe_value($message, 'data', array());
        $marketId = $this->safe_value($message, 'market', array());
        if (is_array($this->markets_by_id) && array_key_exists($marketId, $this->markets_by_id)) {
            $market = $this->markets_by_id[$marketId];
            $symbol = $market['symbol'];
            $messageHash = $this->get_message_hash($message);
            $tradesLimit = $this->safe_integer($this->options, 'tradesLimit', 1000);
            $stored = $this->safe_value($this->trades, $symbol, array());
            if (gettype($data) === 'array' && count(array_filter(array_keys($data), 'is_string')) == 0) {
                $trades = $this->parse_trades($data, $market);
                for ($i = 0; $i < count($trades); $i++) {
                    $stored[] = $trades[$i];
                    $storedLength = is_array($stored) ? count($stored) : 0;
                    if ($storedLength > $tradesLimit) {
                        array_shift($stored);
                    }
                }
            } else {
                $trade = $this->parse_trade($message, $market);
                $stored[] = $trade;
                $length = is_array($stored) ? count($stored) : 0;
                if ($length > $tradesLimit) {
                    array_shift($stored);
                }
            }
            $this->trades[$symbol] = $stored;
            $client->resolve ($stored, $messageHash);
        }
        return $message;
    }

    public function handle_subscription_status($client, $message) {
        // todo => handle unsubscription status
        // array('type' => 'subscribed', 'channel' => 'trades', 'market' => 'BTC-PERP')
        return $message;
    }

    public function handle_unsubscription_status($client, $message) {
        // todo => handle unsubscription status
        // array('type' => 'unsubscribed', 'channel' => 'trades', 'market' => 'BTC-PERP')
        return $message;
    }

    public function handle_info($client, $message) {
        // todo => handle info messages
        // Used to convey information to the user. Is accompanied by a code and msg field.
        // When our servers restart, you may see an info $message with code 20001. If you do, please reconnect.
        return $message;
    }

    public function handle_error($client, $message) {
        return $message;
    }

    public function ping($client) {
        // ftx does not support built-in ws protocol-level ping-pong
        // instead it requires a custom json-based text ping-pong
        // https://docs.ftx.com/#websocket-api
        return array(
            'op' => 'ping',
        );
    }

    public function handle_pong($client, $message) {
        $client->lastPong = $this->milliseconds();
        return $message;
    }
}