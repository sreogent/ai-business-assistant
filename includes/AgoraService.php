<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Agora RTC Token Service (AccessToken2 / Token007)
 * Генерирует защищённые токены для голосовой телефонии и звонков в реальном времени.
 */

class AgoraUtil {
    public static function packUint16(int $x): string { 
        return pack('v', $x); 
    }
    public static function packUint32(int $x): string { 
        return pack('V', $x); 
    }
    public static function packString(string $str): string { 
        return pack('v', strlen($str)) . $str; 
    }
    public static function packMapUint32(array $arr): string {
        ksort($arr);
        $kv = '';
        foreach ($arr as $k => $v) { 
            $kv .= self::packUint16((int)$k) . self::packUint32((int)$v); 
        }
        return self::packUint16(count($arr)) . $kv;
    }
}

class AgoraServiceItem {
    public int $type;
    public array $privileges = [];

    public function __construct(int $type) { 
        $this->type = $type; 
    }

    public function addPrivilege(int $privilege, int $expire): void { 
        $this->privileges[$privilege] = $expire; 
    }

    public function getServiceType(): int { 
        return $this->type; 
    }

    public function pack(): string { 
        return AgoraUtil::packUint16($this->type) . AgoraUtil::packMapUint32($this->privileges); 
    }
}

class AgoraServiceRtc extends AgoraServiceItem {
    public const SERVICE_TYPE = 1;
    public const PRIVILEGE_JOIN_CHANNEL = 1;
    public const PRIVILEGE_PUBLISH_AUDIO_STREAM = 2;
    public const PRIVILEGE_PUBLISH_VIDEO_STREAM = 3;
    public const PRIVILEGE_PUBLISH_DATA_STREAM = 4;

    public string $channelName;
    public string $uid;

    public function __construct(string $channelName = '', string $uid = '0') {
        parent::__construct(self::SERVICE_TYPE);
        $this->channelName = $channelName;
        $this->uid = (string)$uid;
    }

    public function pack(): string {
        return parent::pack() . AgoraUtil::packString($this->channelName) . AgoraUtil::packString($this->uid);
    }
}

class AgoraAccessToken2 {
    public const VERSION = '007';

    public string $appId;
    public string $appCert;
    public int $expire;
    public int $issueTs;
    public int $salt;
    public array $services = [];

    public function __construct(string $appId, string $appCert, int $expire = 7200) {
        $this->appId = $appId;
        $this->appCert = $appCert;
        $this->expire = $expire;
        $this->issueTs = time();
        $this->salt = rand(1, 99999999);
    }

    public function addService(AgoraServiceItem $service): void { 
        $this->services[$service->getServiceType()] = $service; 
    }

    public function getSign(): string {
        $hh = hash_hmac('sha256', $this->appCert, AgoraUtil::packUint32($this->issueTs), true);
        return hash_hmac('sha256', $hh, AgoraUtil::packUint32($this->salt), true);
    }

    public function build(): string {
        $signing = $this->getSign();
        $data = AgoraUtil::packString($this->appId) .
                AgoraUtil::packUint32($this->issueTs) .
                AgoraUtil::packUint32($this->expire) .
                AgoraUtil::packUint32($this->salt) .
                AgoraUtil::packUint16(count($this->services));

        ksort($this->services);
        foreach ($this->services as $service) { 
            $data .= $service->pack(); 
        }

        $signature = hash_hmac('sha256', $data, $signing, true);
        return self::VERSION . base64_encode(zlib_encode(AgoraUtil::packString($signature) . $data, ZLIB_ENCODING_DEFLATE));
    }
}

class AgoraService {
    public const APP_ID = 'ab0b6ec247584690be96497d0e11fcf8';
    public const APP_CERTIFICATE = '9404cf10c5414b22b2a0f53a07f94fe4';

    /**
     * Создать RTC-токен для голосового звонка
     *
     * @param string $channelName Имя канала/комнаты вызова (например, client_42)
     * @param string $uid Уникальный ID пользователя (0 для авто-назначения)
     * @param int $expireTime Срок действия токена в секундах (по умолчанию 2 часа)
     * @return string Токен доступа
     */
    public static function createRtcToken(string $channelName, string $uid = '0', int $expireTime = 7200): string {
        $tokenBuilder = new AgoraAccessToken2(self::APP_ID, self::APP_CERTIFICATE, $expireTime);
        $rtc = new AgoraServiceRtc($channelName, $uid);
        $rtc->addPrivilege(AgoraServiceRtc::PRIVILEGE_JOIN_CHANNEL, $expireTime);
        $rtc->addPrivilege(AgoraServiceRtc::PRIVILEGE_PUBLISH_AUDIO_STREAM, $expireTime);
        $tokenBuilder->addService($rtc);
        return $tokenBuilder->build();
    }
}
