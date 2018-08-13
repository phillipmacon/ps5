<?php

namespace PlayStation\Api;

use PlayStation\Client;
use PlayStation\SessionType;

use PlayStation\Api\Trophy;
use PlayStation\Api\Session;
use PlayStation\Api\MessageThread;

class User extends AbstractApi {

    public const USERS_ENDPOINT = 'https://us-prof.np.community.playstation.net/userProfile/v1/users/%s/';

    private $onlineId;
    private $onlineIdParameter;

    private $_sessions = [];

    public function __construct(Client $client, string $onlineId = null) 
    {
        parent::__construct($client);

        $this->onlineId = $onlineId;
        $this->onlineIdParameter = $this->onlineId ?? "me";
    }

    public function getInfo() 
    {
        return $this->get(sprintf(self::USERS_ENDPOINT . 'profile2', $this->onlineIdParameter), [
            'fields' => 'npId,onlineId,accountId,avatarUrls,plus,aboutMe,languagesUsed,trophySummary(@default,progress,earnedTrophies),isOfficiallyVerified,personalDetail(@default,profilePictureUrls),personalDetailSharing,personalDetailSharingRequestMessageFlag,primaryOnlineStatus,presences(@titleInfo,hasBroadcastData),friendRelation,requestMessageFlag,blocking,mutualFriendsCount,following,followerCount,friendsCount,followingUsersCount&avatarSizes=m,xl&profilePictureSizes=m,xl&languagesUsedLanguageSet=set3&psVitaTitleIcon=circled&titleIconSize=s'
        ]);
    }

    public function getOnlineId() 
    {
        return $this->onlineId;
    }
    
    public function add(string $requestMessage = null)
    {
        if ($this->onlineId === null) return;

        $data = ($requestMessage === null) ? new \stdClass() : [
            "requestMessage" => $requestMessage
        ];

        return $this->postJson(sprintf(self::USERS_ENDPOINT . 'friendList/%s', $this->client->getOnlineId(), $this->onlineId), $data);
    }

    public function remove() 
    {
        if ($this->onlineId === null) return;

        return $this->delete(sprintf(self::USERS_ENDPOINT . 'friendList/%s', $this->client->getOnlineId(), $this->onlineId));
    }

    public function block()
    {
        if ($this->onlineId === null) return;

        return $this->post(sprintf(self::USERS_ENDPOINT . 'blockList/%s', $this->client->getOnlineId(), $this->onlineId), null);
    }

    public function unblock()
    {
        if ($this->onlineId === null) return;

        return $this->delete(sprintf(self::USERS_ENDPOINT . 'blockList/%s', $this->client->getOnlineId(), $this->onlineId));
    }

    public function getFriends($filter = 'online', $limit = 36) : array
    {
        $result = [];

        $friends = $this->get(sprintf(self::USERS_ENDPOINT . 'friends/profiles2', $this->onlineIdParameter), [
            'fields' => 'onlineId',
            'limit' => $limit,
            'sort' => 'name-onlineId'
        ]);
        
        foreach ($friends->profiles as $friend) {
            $result[] = new self($this->client, $friend->onlineId);
        }

        return $result;
    }

    public function trophies(int $limit = 36) : array 
    {
        $returnTrophies = [];

        $data = [
            'fields' => '@default',
            'npLanguage' => 'en',
            'iconSize' => 'm',
            'platform' => 'PS3,PSVITA,PS4',
            'offset' => 0,
            'limit' => $limit
        ];

        if ($this->getOnlineId() != null) {
            $data['comparedUser'] = $this->getOnlineId();
        }

        $trophies = $this->get(Trophy::TROPHY_ENDPOINT . 'trophyTitles', $data);

        foreach ($trophies->trophyTitles as $trophy) {
            $returnTrophies[] = new Trophy($this->client, $trophy, $this, $this->getOnlineId() != null);
        }
        
        return $returnTrophies;
    }

    public function sendMessage(string $message) : ?Message 
    {
        $thread = $this->getMessageGroup();

        if ($thread === null) return null;

        return $thread->sendMessage($message);
    }

    public function sendImage(string $imageContents) : ?Message
    {
        $thread = $this->getMessageGroup();
        
        if ($thread === null) return null;

        return $thread->sendImage($imageContents);
    }

    public function sendAudio(string $audioContents, int $audioLengthSeconds) : ?Message
    {
        $thread = $this->getMessageGroup();
        
        if ($thread === null) return null;

        return $thread->sendAudio($audioContents, $audioLengthSeconds);
    }

    public function getMessageThreads() : array
    {
        $returnThreads = [];

        $threads = $this->get(MessageThread::MESSAGE_THREAD_ENDPOINT . 'users/me/threadIds', [
            'withOnlineIds' => $this->getOnlineId()
        ]);

        if (empty($threads->threadIds)) return [];

        foreach ($threads->threadIds as $thread) {
            $returnThreads[] = new MessageThread($this->client, $thread->threadId);
        }

        return $returnThreads;
    }

    public function getPrivateMessageThread() : ?MessageThread
    {
        $threads = $this->getMessageThreads();

        if (count($threads) === 0) return null;
        
        foreach ($threads as $thread) {
            if ($thread->getMemberCount() === 2) {
                return $thread;
            }
        }

        return null;
    }

    public function partySession() : ?Session 
    {
        $sessions = $this->filterSessions(SessionType::Party);
        
        return $sessions[0] ?? null;
    }

    public function gameSession() : ?Session
    {
        $sessions = $this->filterSessions(SessionType::Game);
        
        return $sessions[0] ?? null;
    }


    private function getMessageGroup() 
    {
        if ($this->getOnlineId() === null) return null;

        $thread = $this->getPrivateMessageThread();

        if ($thread === null) {
            // If we couldn't find an existing message thread, let's make one.
            
            $data = (object)[
                'threadDetail' => (object)[
                    'threadMembers' => [
                        (object)[
                            'onlineId' => $this->getOnlineId()
                        ],
                        (object)[
                            'onlineId' => $this->client->getOnlineId()
                        ]
                    ]
                ]
            ];

            $response = $this->postMultiPart(MessageThread::MESSAGE_THREAD_ENDPOINT . 'threads/', 'threadDetail', json_encode($data, JSON_PRETTY_PRINT), [
                'Content-Type' => 'application/json; charset=utf-8'
            ]);

            $thread = new MessageThread($this->client, $response->threadId);
        }

        return $thread;
    }


    private function filterSessions(int $type) : array
    {
        $sessions = $this->sessions();
        
        $filteredSession = array_filter($sessions, function($session) use ($type) {
            if ($session->getTitleType() & $type) return $session;
        });

        return $filteredSession;
    }

    private function sessions() : array
    {
        if (!empty($_sessions)) return $_sessions;

        $returnSessions = [];

        $sessions = $this->get(sprintf(Session::SESSION_ENDPOINT, $this->onlineIdParameter), [
            'fields' => '@default,npTitleDetail,npTitleDetail.platform,sessionName,sessionCreateTimestamp,availablePlatforms,members,memberCount,sessionMaxUser',
            'titleIconSize' => 's',
            'npLanguage' => 'en'
        ]);

        if ($sessions->size === 0) return null;

        // Multiple sessions could be used if the user is in a party while playing a game.
        foreach ($sessions->sessions as $session) {
            $returnSessions[] = new Session($this->client, $session);
        }

        $_sessions = $returnSessions;

        return $returnSessions;
    }
}