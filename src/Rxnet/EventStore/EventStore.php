<?php

namespace Rxnet\EventStore;

use EventLoop\EventLoop;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
use React\EventLoop\LoopInterface;
use Rx\Disposable\CallbackDisposable;
use Rx\DisposableInterface;
use Rx\Observable;
use Rx\ObservableInterface;
use Rx\Observer\CallbackObserver;
use Rx\ObserverInterface;
use Rx\Subject\ReplaySubject;
use Rx\Subject\Subject;
use Rxnet\Socket;
use Rxnet\EventStore\Data\ConnectToPersistentSubscription;
use Rxnet\EventStore\Data\NewEvent;
use Rxnet\EventStore\Data\NotHandled;
use Rxnet\EventStore\Data\NotHandled_MasterInfo;
use Rxnet\EventStore\Data\PersistentSubscriptionConfirmation;
use Rxnet\EventStore\Data\PersistentSubscriptionStreamEventAppeared;
use Rxnet\EventStore\Data\ReadAllEvents;
use Rxnet\EventStore\Data\ReadEvent;
use Rxnet\EventStore\Data\ReadEventCompleted;
use Rxnet\EventStore\Data\ReadStreamEvents;
use Rxnet\EventStore\Data\ReadStreamEventsCompleted;
use Rxnet\EventStore\Data\ResolvedIndexedEvent;
use Rxnet\EventStore\Data\StreamEventAppeared;
use Rxnet\EventStore\Data\SubscribeToStream;
use Rxnet\EventStore\Data\SubscriptionConfirmation;
use Rxnet\EventStore\Data\SubscriptionDropped;
use Rxnet\EventStore\Data\TransactionStart;
use Rxnet\EventStore\Data\TransactionStartCompleted;
use Rxnet\EventStore\Data\UnsubscribeFromStream;
use Rxnet\EventStore\Data\WriteEvents;
use Rxnet\EventStore\Exception\NotMasterException;
use Rxnet\EventStore\Message\Credentials;
use Rxnet\EventStore\Message\MessageType;
use Rxnet\EventStore\Message\SocketMessage;
use Rxnet\EventStore\NewEvent\NewEventInterface;
use Zend\Stdlib\Exception\LogicException;

class EventStore
{
    const POSITION_START = 0;
    const POSITION_END = -1;
    const POSITION_LATEST = 999999;
    /** @var LoopInterface */
    protected $dns;
    /** @var ReadBuffer */
    protected $readBuffer;
    /** @var Writer */
    protected $writer;
    /** @var Socket\Connection */
    protected $stream;
    /** @var Socket\Connection */
    protected $connector;
    /** @var Subject */
    protected $connectionSubject;
    /** @var DisposableInterface */
    protected $heartBeatDisposable;
    /** @var  int */
    protected $heartBeatRate;
    /** @var  DisposableInterface */
    protected $readBufferDisposable;
    protected $loop;
    /** @var  array */
    protected $dsn;

    /**
     * EventStore constructor.
     * @param LoopInterface|null $loop
     * @param ReadBuffer|null $readBuffer
     * @param Writer|null $writer
     */
    public function __construct(LoopInterface $loop = null, ReadBuffer $readBuffer = null, Writer $writer = null)
    {
        $this->loop = $loop ?: EventLoop::getLoop();
        $this->readBuffer = $readBuffer ?: new ReadBuffer();
        $this->writer = $writer ?: new Writer();
        $this->connector = new Socket\Connector($this->loop);
    }

    /**
     * @param string $dsn tcp://user:password@host:port
     * @param int $connectTimeout in milliseconds
     * @param int $heartBeatRate in milliseconds
     * @return Observable
     */
    public function connect($dsn = 'tcp://admin:changeit@localhost:1113', $connectTimeout = 1000, $heartBeatRate = 5000)
    {
        // connector compatibility
        $connectTimeout = ($connectTimeout > 0) ? $connectTimeout / 1000 : 0;
        $this->heartBeatRate = $heartBeatRate;

        if (!stristr($dsn, '://')) {
            $dsn = 'tcp://' . $dsn;
        }
        $parsedDsn = parse_url($dsn);
        if (!isset($parsedDsn['host'])) {
            throw new \InvalidArgumentException('Invalid connection DNS given format should be : tcp://user:password@host:port');
        }
        if (!isset($parsedDsn['port'])) {
            $parsedDsn['port'] = 1113;
        }
        if (!isset($parsedDsn['user'])) {
            $parsedDsn['user'] = 'admin';
        }
        if (!isset($parsedDsn['pass'])) {
            $parsedDsn['pass'] = 'changeit';
        }
        $this->dsn = $parsedDsn;
        // What you should observe if you want to auto reconnect
        $this->connectionSubject = new ReplaySubject(1, 1);


        return Observable::create(function (ObserverInterface $observer) use ($dsn) {
            $this->connector->connect($dsn)
                ->flatMap(function (Socket\Connection $stream) {
                    // send all data to our read buffer
                    $this->stream = $stream;
                    $this->readBufferDisposable = $this->stream->subscribe($this->readBuffer);

                    // common object to write to socket
                    $this->writer->setSocketStream($this->stream);
                    $this->writer->setCredentials(new Credentials($this->dsn['user'], $this->dsn['pass']));

                    // start heartbeat listener
                    $this->heartBeatDisposable = $this->heartbeat();

                    // Forward internal errors to the connect result
                    return $this->connectionSubject
                        ->startWith('/eventstore/connected');
                })
                ->subscribe($observer);

            return new CallbackDisposable(function () {
                if ($this->readBufferDisposable instanceof DisposableInterface) {
                    $this->readBufferDisposable->dispose();
                }
                if ($this->heartBeatDisposable instanceof DisposableInterface) {
                    $this->heartBeatDisposable->dispose();
                }
                if ($this->stream instanceof Socket\Connection) {
                    $this->stream->close();
                }
            });
        });
    }

    /**
     * @param $host
     * @param $port
     * @return Observable\AnonymousObservable
     */
    protected function reconnect($host, $port)
    {
        $this->dsn['host'] = $host;
        $this->dsn['port'] = $port;
        return $this->connector->connect($host, $this->dsn['port'])
            ->flatMap(function (Socket\Connection $connection) {
                // send all data to our read buffer
                $this->stream = $connection;
                $this->readBufferDisposable->dispose();
                $this->readBufferDisposable = $this->stream->subscribe($this->readBuffer);
                $this->stream->resume();

                // common object to write to socket
                $this->writer->setSocketStream($this->stream);
                $this->writer->setCredentials(new Credentials($this->dsn['user'], $this->dsn['pass']));

                // start heartbeat listener
                $this->heartBeatDisposable->dispose();
                $this->heartBeatDisposable = $this->heartbeat();

                // Forward internal errors to the connect result
                return $this->connectionSubject->startWith('/eventstore/re-connected');
            });

    }

    /**
     * Intercept heartbeat message and answer automatically
     * @return DisposableInterface
     */
    protected function heartbeat()
    {
        return $this->readBuffer
            ->timeout($this->heartBeatRate)
            ->filter(
                function (SocketMessage $message) {
                    return $message->getMessageType()->getType() === MessageType::HEARTBEAT_REQUEST;
                }
            )
            ->subscribe(
                new CallbackObserver(
                    function (SocketMessage $message) {
                        $this->writer->composeAndWrite(MessageType::HEARTBEAT_RESPONSE, null, $message->getCorrelationID());
                    },
                    [$this->connectionSubject, 'onError']
                )
            );
    }

    /**
     * @param string $streamId
     * @param NewEventInterface[] $events
     * @param int $expectedVersion
     * @param bool $requireMaster
     * @return ObservableInterface(WriteEventsCompleted) with WriteEventsCompleted
     */
    public function write($streamId, $events, $expectedVersion = -2, $requireMaster = false)
    {
        if (!is_array($events)) {
            $events = [$events];
        }
        if (!$events) {
            throw new \LogicException('No events added');
        }
        $query = new WriteEvents();
        $query->setEventStreamId($streamId);
        $query->setRequireMaster($requireMaster);
        $query->setExpectedVersion($expectedVersion);

        $array = new RepeatedField(GPBType::MESSAGE, NewEvent::class);
        $query->setEvents($array);
        foreach ($events as $event) {
            $array[] = $event->getMessage();
        }
        $correlationID = $this->writer->createUUIDIfNeeded();
        return $this->writer->composeAndWrite(MessageType::WRITE_EVENTS, $query, $correlationID)
            ->merge($this->readBuffer->waitFor($correlationID, 1));

    }

    /**
     * @param $streamId
     * @param int $expectedVersion
     * @param bool $requireMaster
     * @return Observable
     */
    public function startTransaction($streamId, $expectedVersion = -2, $requireMaster = false)
    {
        $query = new TransactionStart();
        $query->setEventStreamId($streamId);
        $query->setRequireMaster($requireMaster);
        $query->setExpectedVersion($expectedVersion);

        $correlationID = $this->writer->createUUIDIfNeeded();
        return $this->writer->composeAndWrite(MessageType::TRANSACTION_START, $query, $correlationID)
            ->merge($this->readBuffer->waitFor($correlationID, 1))
            ->map(function (TransactionStartCompleted $startCompleted) use ($requireMaster) {
                return new Transaction($startCompleted->getTransactionId(), $requireMaster, $this->writer, $this->readBuffer);
            });
    }

    /**
     * This kind of subscription specifies a starting point, in the form of an event number
     * or transaction file position.
     * The given function will be called for events from the starting point until the end of the stream,
     * and then for subsequently written events.
     *
     * For example, if a starting point of 50 is specified when a stream has 100 events in it,
     * the subscriber can expect to see events 51 through 100, and then any events subsequently
     * written until such time as the subscription is dropped or closed.
     *
     * @param $streamId
     * @param int $startFrom
     * @param bool $resolveLink
     * @return Observable
     */
    public function catchUpSubscription($streamId, $startFrom = self::POSITION_START, $resolveLink = false)
    {
        return $this->readEventsForward($streamId, $startFrom, self::POSITION_LATEST, $resolveLink)
            ->concat($this->volatileSubscription($streamId, $resolveLink));
    }

    /**
     * This kind of subscription calls a given function for events written after
     * the subscription is established.
     *
     * For example, if a stream has 100 events in it when a subscriber connects,
     * the subscriber can expect to see event number 101 onwards until the time
     * the subscription is closed or dropped.
     *
     * @param string $streamId
     * @param bool $resolveLink
     * @return Observable
     */
    public function volatileSubscription($streamId, $resolveLink = false)
    {
        $event = new SubscribeToStream();
        $event->setEventStreamId($streamId);
        $event->setResolveLinkTos($resolveLink);

        return Observable::create(function (ObserverInterface $observer) use ($event) {
            $correlationID = $this->writer->createUUIDIfNeeded();
            $this->writer
                ->composeAndWrite(
                    MessageType::SUBSCRIBE_TO_STREAM,
                    $event,
                    $correlationID
                )
                // When written wait for all responses
                ->merge(
                    $this->readBuffer
                        ->filter(
                            function (SocketMessage $message) use ($correlationID) {
                                // Use same correlationID to pass by this filter
                                return $message->getCorrelationID() == $correlationID;
                            }
                        )
                )
                ->flatMap(
                    function (SocketMessage $message) {
                        $data = $message->getData();
                        //var_dump($data);
                        switch (get_class($data)) {
                            case SubscriptionDropped::class :
                                return Observable::error(new \Exception("Subscription dropped, for reason : {$data->getReason()}"));
                            case SubscriptionConfirmation::class :
                                return Observable::empty();
                            default :
                                return Observable::of($data);
                        }
                    }
                )
                ->map(
                    function (StreamEventAppeared $eventAppeared) use ($correlationID) {
                        $record = $eventAppeared->getEvent()->getEvent();
                        /* @var \Rxnet\EventStore\Data\EventRecord $record */

                        return new EventRecord(
                            $record,
                            $correlationID,
                            $this->writer
                        );
                    }
                )
                ->subscribe($observer);

            return new CallbackDisposable(function () {
                $event = new UnsubscribeFromStream();
                $this->writer->composeAndWrite(
                    MessageType::UNSUBSCRIBE_FROM_STREAM,
                    $event
                );
            });
        });
    }

    /**
     * @param $streamID
     * @param $group
     * @param $parallel
     * @return Observable
     */
    public function persistentSubscription($streamID, $group, $parallel = 1)
    {
        $correlationID = $this->writer->createUUIDIfNeeded();
        return $this->connectToPersistentSubscription($streamID, $group, $parallel, $correlationID)
            ->catch(function (\Exception $e) use ($streamID, $group, $parallel, $correlationID) {
                if ($e instanceOf NotMasterException) {
                    // Reconnect if not master
                    return $this->reconnect($e->getMasterIp(), $e->getMasterPort())
                        ->concat($this->connectToPersistentSubscription($streamID, $group, $parallel, $correlationID))
                        ->flatMap(function () use ($streamID, $group, $parallel, $correlationID) {
                            return $this->connectToPersistentSubscription($streamID, $group, $parallel, $correlationID);
                        });
                }
                throw $e;
            })
            ->map(
                function (PersistentSubscriptionStreamEventAppeared $eventAppeared) use ($correlationID, $group) {
                    $record = $eventAppeared->getEvent()->getEvent();
                    //$link = $eventAppeared->getEvent()->getLink();
                    /* @var \Rxnet\EventStore\Data\EventRecord $record */

                    return new AcknowledgeableEventRecord(
                        $record,
                        $correlationID,
                        $group,
                        $this->writer
                    );
                }
            );
    }

    /**
     * @param $streamID
     * @param $group
     * @param int $parallel
     * @param $correlationID
     * @return Observable
     */
    protected function connectToPersistentSubscription($streamID, $group, $parallel = 1, $correlationID)
    {
        $query = new ConnectToPersistentSubscription();
        $query->setEventStreamId($streamID);
        $query->setSubscriptionId($group);
        $query->setAllowedInFlightMessages($parallel);

        return Observable::create(function (ObserverInterface $observer) use ($correlationID, $query, $group) {
            $this->writer
                ->composeAndWrite(
                    MessageType::CONNECT_TO_PERSISTENT_SUBSCRIPTION,
                    $query,
                    $correlationID
                )
                // When written wait for all responses
                ->merge($this->readBuffer->waitFor($correlationID, -1))
                ->flatMap(
                    function ($data) use ($query) {
                        switch (get_class($data)) {
                            case SubscriptionDropped::class :
                                return Observable::error(new \Exception("Subscription dropped, for reason : {$data->getReason()}"));
                            case PersistentSubscriptionConfirmation::class :
                                return Observable::empty();
                            case PersistentSubscriptionStreamEventAppeared::class :
                                return Observable::of($data);

                            case NotHandled_MasterInfo::class:
                                /* @var NotHandled_MasterInfo $data */
                                return Observable::error(new NotMasterException($data->getExternalTcpAddress(), $data->getExternalTcpPort()));
                            case NotHandled::class :
                                if ($data->getReason() == 0) {
                                    return Observable::error(new LogicException("Server is not ready {$data->getAdditionalInfo()}", 0));
                                }
                                return Observable::error(new LogicException("Server is too busy {$data->getAdditionalInfo()}", 1));
                            default:
                                // Why are we here ?
                                var_dump($data);
                                return Observable::error(new LogicException("Unknown data received : " . get_class($data)));
                        }
                    }
                )
                ->subscribe($observer);

            return new CallbackDisposable(function () {
                $event = new UnsubscribeFromStream();
                $this->writer->composeAndWrite(
                    MessageType::UNSUBSCRIBE_FROM_STREAM,
                    $event
                );
            });
        });
    }

    /**
     * @param $streamId
     * @param int $number
     * @param bool $resolveLinkTos
     * @param bool $requireMaster
     * @return Observable
     */
    public function readEvent($streamId, $number = 0, $resolveLinkTos = false, $requireMaster = false)
    {
        $event = new ReadEvent();
        $event->setEventStreamId($streamId);
        $event->setEventNumber($number);
        $event->setResolveLinkTos($resolveLinkTos);
        $event->setRequireMaster($requireMaster);

        $correlationID = $this->writer->createUUIDIfNeeded();
        return $this->writer->composeAndWrite(MessageType::READ, $event, $correlationID)
            ->merge($this->readBuffer->waitFor($correlationID, 1))
            ->map(function (ReadEventCompleted $data) {
                return new EventRecord($data->getEvent()->getEvent());
            });
    }

    /**
     * @param bool $resolveLinkTos
     * @param bool $requireMaster
     * @return Observable
     */
    public function readAllEvents($resolveLinkTos = false, $requireMaster = false)
    {
        $query = new ReadAllEvents();
        $query->setRequireMaster($requireMaster);
        $query->setResolveLinkTos($resolveLinkTos);

        return $this->readEvents($query, MessageType::READ_ALL_EVENTS_FORWARD);
    }

    /**
     * @param $streamId
     * @param int $fromEvent
     * @param int $max
     * @param bool $resolveLinkTos
     * @param bool $requireMaster
     * @return Observable
     */
    public function readEventsForward($streamId, $fromEvent = self::POSITION_START, $max = self::POSITION_LATEST, $resolveLinkTos = false, $requireMaster = false)
    {
        $query = new ReadStreamEvents();
        $query->setRequireMaster($requireMaster);
        $query->setEventStreamId($streamId);
        $query->setFromEventNumber($fromEvent);
        $query->setMaxCount($max);
        $query->setResolveLinkTos($resolveLinkTos);

        return $this->readEvents($query, MessageType::READ_STREAM_EVENTS_FORWARD);
    }

    /**
     * @param $streamId
     * @param int $fromEvent
     * @param int $max
     * @param bool $resolveLinkTos
     * @param bool $requireMaster
     * @return Observable
     */
    public function readEventsBackward($streamId, $fromEvent = self::POSITION_END, $max = 10, $resolveLinkTos = false, $requireMaster = false)
    {
        $query = new ReadStreamEvents();
        $query->setRequireMaster($requireMaster);
        $query->setEventStreamId($streamId);
        $query->setFromEventNumber($fromEvent);
        $query->setMaxCount($max);
        $query->setResolveLinkTos($resolveLinkTos);

        return $this->readEvents($query, MessageType::READ_STREAM_EVENTS_BACKWARD);
    }

    /**
     * Helper to read all events, repeat query until end reached
     * @param Message $query
     * @param int $messageType
     * @return Observable
     */
    protected function readEvents(Message $query, $messageType)
    {
        // TODO backpressure, wait for event's array to be readed completely before asking for more in stream
        // OnDemand ? onBackpressureBuffer ?
        return Observable::create(function (ObserverInterface $observer) use ($messageType, $query) {
            $end = false;
            $maxPossible = 10; //4096
            $max = ($query instanceof ReadStreamEvents) ? $query->getMaxCount() : self::POSITION_LATEST;

            $asked = $max;
            if ($max >= $maxPossible) {
                $max = $maxPossible;
                $query->setMaxCount($max);
            }
            $correlationID = $this->writer->createUUIDIfNeeded();

            $this->writer->composeAndWrite($messageType, $query, $correlationID)
                // When written wait for all responses
                ->merge($this->readBuffer->waitFor($correlationID, -1))
                // Throw if we have an error message
                ->flatMap(function (ReadStreamEventsCompleted $event) {
                    if ($error = $event->getError()) {
                        return Observable::error(new \Exception($error));
                    }
                    return Observable::of($event);
                })
                // If more data is needed do another query
                ->doOnNext(function (ReadStreamEventsCompleted $event) use ($query, $correlationID, &$end, &$asked, $max, $maxPossible, $messageType) {
                    $records = $event->getEvents();
                    $asked -= count($records);
                    if ($event->getIsEndOfStream()) {
                        $end = true;
                    } elseif ($asked <= 0 && $max != self::POSITION_LATEST) {
                        $end = true;
                    }
                    if (!$end) {
                        $start = $records[count($records) - 1];
                        /* @var ResolvedIndexedEvent $start */
                        $start = ($messageType == MessageType::READ_STREAM_EVENTS_FORWARD) ? $start->getEvent()->getEventNumber() + 1 : $start->getEvent()->getEventNumber() - 1;
                        $query->setFromEventNumber($start);
                        $query->setMaxCount($asked > $maxPossible ? $maxPossible : $asked);

                        //echo "Not end of stream need slice from position {$start} next is {$event->getNextEventNumber()} \n";
                        $this->writer->composeAndWrite(
                            $messageType,
                            $query,
                            $correlationID
                        );
                    }
                })
                // Continue to watch until we have all our results (or end)
                ->takeWhile(function () use (&$end) {
                    return !$end;
                })
                // Format EventRecord for easy reading
                ->flatMap(function (ReadStreamEventsCompleted $event) use (&$asked, &$end) {
                    /* @var ReadStreamEventsCompleted $event */
                    $records = [];
                    /* @var \Rxnet\EventStore\EventRecord[] $records */
                    $events = $event->getEvents();
                    foreach ($events as $item) {
                        /* @var \Rxnet\EventStore\Data\ResolvedIndexedEvent $item */
                        $records[] = new EventRecord($item->getEvent());
                    }
                    // Will emit onNext for each event
                    return Observable::fromArray($records);
                })
                ->subscribe($observer);
        });
    }
}