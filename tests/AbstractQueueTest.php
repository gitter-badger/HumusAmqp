<?php
/**
 * Copyright (c) 2016. Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 *  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 *  "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 *  LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 *  A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 *  OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 *  SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 *  LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 *  DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 *  THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 *  (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 *  OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 *  This software consists of voluntary contributions made by many individuals
 *  and is licensed under the MIT license.
 */

declare (strict_types=1);

namespace HumusTest\Amqp;

use Humus\Amqp\Channel;
use Humus\Amqp\Connection;
use Humus\Amqp\Envelope;
use Humus\Amqp\Exchange;
use Humus\Amqp\Queue;
use Humus\Amqp\Constants;
use HumusTest\Amqp\Helper\CanCreateExchange;
use HumusTest\Amqp\Helper\CanCreateQueue;
use HumusTest\Amqp\Helper\DeleteOnTearDownTrait;
use PHPUnit_Framework_TestCase as TestCase;

/**
 * Class AbstractQueueTest
 * @package HumusTest\Amqp
 */
abstract class AbstractQueueTest extends TestCase implements
    CanCreateExchange,
    CanCreateQueue
{
    use DeleteOnTearDownTrait;

    /**
     * @var Channel
     */
    protected $channel;

    /**
     * @var Exchange
     */
    protected $exchange;

    /**
     * @var Queue
     */
    protected $queue;

    protected function setUp()
    {
        $connection = $this->createConnection();
        $this->channel = $connection->newChannel();

        $this->exchange = $this->createExchange($this->channel);
        $this->exchange->setType('topic');
        $this->exchange->setName('test-exchange');
        $this->exchange->declareExchange();

        $this->queue = $this->createQueue($this->channel);
        $this->queue->setName('test-queue');
        $this->queue->declareQueue();
        $this->queue->bind('test-exchange', '#');

        $this->addToCleanUp($this->exchange);
        $this->addToCleanUp($this->queue);
    }

    /**
     * @test
     */
    public function it_sets_name_flags_type_and_arguments()
    {
        $this->assertEquals('test-queue', $this->queue->getName());
        $this->assertEmpty($this->queue->getArguments());

        $this->queue->setName('test');

        $this->assertEquals('test', $this->queue->getName());

        $this->queue->setFlags(Constants::AMQP_DURABLE);

        $this->assertEquals(2, $this->queue->getFlags());

        $this->queue->setFlags(Constants::AMQP_PASSIVE | Constants::AMQP_DURABLE);

        $this->assertEquals(6, $this->queue->getFlags());

        $this->queue->setArgument('key', 'value');

        $this->assertEquals('value', $this->queue->getArgument('key'));
        $this->assertFalse($this->queue->getArgument('invalid key'));

        $this->queue->setArguments([
            'foo' => 'bar',
            'baz' => 'bam'
        ]);

        $this->assertEquals(
            [
                'foo' => 'bar',
                'baz' => 'bam'
            ],
            $this->queue->getArguments()
        );
    }

    /**
     * @test
     */
    public function it_binds_with_arguments()
    {
        $this->queue->unbind('test-exchange', '#');
        $this->queue->bind('test-exchange', '', [
            'foo' => 'bar',
            'baz' => 1,
            'bam' => true,
            'array' => [
                's' => 'f'
            ]
        ]);
    }

    /**
     * @test
     */
    public function it_unbinds_queue()
    {
        $this->queue->unbind('test-exchange');
    }

    /**
     * @test
     */
    public function it_returns_channel_and_connection()
    {
        $this->assertInstanceOf(Channel::class, $this->queue->getChannel());
        $this->assertInstanceOf(Connection::class, $this->queue->getConnection());
    }

    /**
     * @test
     */
    public function it_consumes_with_callback()
    {
        $this->exchange->publish('foo');
        $this->exchange->publish('bar');

        $result = [];
        $cnt = 2;
        $this->queue->consume(function (Envelope $envelope, Queue $queue) use (&$result, &$cnt) {
            $result[] = $envelope->getBody();
            $result[] = $queue->getName();
            $cnt--;
            return ($cnt > 0);
        });

        $this->assertEquals(
            [
                'foo',
                'test-queue',
                'bar',
                'test-queue',
            ],
            $result
        );
    }

    /**
     * @test
     */
    public function it_consumes_without_callback()
    {
        $this->exchange->publish('foo');
        $this->exchange->publish('bar');

        $this->queue->consume(null);
    }

    /**
     * @test
     */
    public function it_produces_and_get_messages_from_queue()
    {
        $this->exchange->publish('foo');
        $this->exchange->publish('bar');

        $msg1 = $this->queue->get(Constants::AMQP_AUTOACK);
        $msg2 = $this->queue->get(Constants::AMQP_AUTOACK);

        $this->assertSame('foo', $msg1->getBody());
        $this->assertSame('bar', $msg2->getBody());
    }

    /**
     * @test
     */
    public function it_produces_transactional_and_get_messages_from_queue()
    {
        $this->channel->startTransaction();
        $this->exchange->publish('foo');
        $this->channel->commitTransaction();

        $this->channel->startTransaction();
        $this->exchange->publish('bar');
        $this->channel->commitTransaction();

        $msg1 = $this->queue->get(Constants::AMQP_AUTOACK);
        $msg2 = $this->queue->get(Constants::AMQP_AUTOACK);

        $this->assertSame('foo', $msg1->getBody());
        $this->assertSame('bar', $msg2->getBody());
    }

    /**
     * @test
     */
    public function it_rolls_back_transation()
    {
        $this->channel->startTransaction();
        $this->exchange->publish('foo');
        $this->channel->rollbackTransaction();

        $msg = $this->queue->get(Constants::AMQP_AUTOACK);

        $this->assertFalse($msg);
    }

    /**
     * @test
     */
    public function it_purges_messages_from_queue()
    {
        $this->channel->startTransaction();
        $this->exchange->publish('foo');
        $this->exchange->publish('bar');
        $this->channel->commitTransaction();

        $msg1 = $this->queue->get(Constants::AMQP_AUTOACK);

        $this->assertInstanceOf(Envelope::class, $msg1);

        $this->queue->purge();

        $msg2 = $this->queue->get(Constants::AMQP_AUTOACK);

        $this->assertFalse($msg2);
    }

    /**
     * @test
     */
    public function it_returns_envelope_information()
    {
        $this->exchange->publish('foo', 'routingKey', Constants::AMQP_NOPARAM, [
            'content_type' => 'text/plain',
            'content_encoding' => 'UTF-8',
            'message_id' => 'some message id',
            'app_id' => 'app id',
            'user_id' => 'guest', // must be same as login data
            'delivery_mode' => 1,
            'priority' => 5,
            'timestamp' => 25,
            'expiration' => 1000,
            'type' => 'message type',
            'headers' => [
                'header1' => 'value1',
                'header2' => 'value2'
            ]
        ]);

        $msg = $this->queue->get(Constants::AMQP_AUTOACK);

        $this->assertFalse($msg->isRedelivery());
        $this->assertEquals('test-exchange', $msg->getExchangeName());
        $this->assertEquals('text/plain', $msg->getContentType());
        $this->assertEquals('UTF-8', $msg->getContentEncoding());
        $this->assertEquals('some message id', $msg->getMessageId());
        $this->assertEquals('app id', $msg->getAppId());
        $this->assertEquals('guest', $msg->getUserId());
        $this->assertEquals(1, $msg->getDeliveryMode());
        $this->assertEquals(1, $msg->getDeliveryTag());
        $this->assertEquals(5, $msg->getPriority());
        $this->assertEquals(25, $msg->getTimestamp());
        $this->assertEquals(1000, $msg->getExpiration());
        $this->assertEquals('message type', $msg->getType());
        $this->assertEquals('routingKey', $msg->getRoutingKey());
        $this->assertEquals(
            [
                'header1' => 'value1',
                'header2' => 'value2'
            ],
            $msg->getHeaders()
        );
        $this->assertTrue($msg->hasHeader('header1'));
        $this->assertFalse($msg->hasHeader('invalid header'));
        $this->assertEquals('value1', $msg->getHeader('header1'));
    }

    /**
     * @test
     */
    public function it_acks()
    {
        $this->exchange->publish('foo');

        $msg = $this->queue->get(Constants::AMQP_NOPARAM);

        $this->queue->ack($msg->getDeliveryTag());

        $msg = $this->queue->get(Constants::AMQP_NOPARAM);

        $this->assertFalse($msg);
    }

    /**
     * @test
     */
    public function it_nacks_and_rejects_message()
    {
        $this->exchange->publish('foo');

        $msg = $this->queue->get(Constants::AMQP_NOPARAM);

        $this->queue->reject($msg->getDeliveryTag(), Constants::AMQP_REQUEUE);

        $msg = $this->queue->get(Constants::AMQP_NOPARAM);

        $this->assertEquals('foo', $msg->getBody());
        $this->assertTrue($msg->isRedelivery());

        $this->queue->nack($msg->getDeliveryTag(), Constants::AMQP_NOPARAM);

        $msg = $this->queue->get(Constants::AMQP_NOPARAM);

        $this->assertFalse($msg);
    }
}
