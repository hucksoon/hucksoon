<?php
// 랭킹 서버 설정. 이 파일을 config.php로 복사해서 값을 채우세요.
// config.php에는 비밀값이 들어가므로 저장소에 올리지 말고(.gitignore에 있음), 가능하면 웹에서 열 수 없는 곳에 두세요.
// 다른 위치에 두려면 웹 서버 환경변수 RANKING_CONFIG에 그 경로를 지정하면 됩니다.
return [
    // HMAC 키: 32자 이상의 무작위 값. `php -r "echo bin2hex(random_bytes(32));"` 로 만들고 따로 안전하게 보관.
    // 잃어버리면 같은 사람을 다시 찾을 수 없고, 바꾸면 기존 기록이 다른 사람처럼 보입니다.
    'hash_key' => '',

    // 랭킹에서 뺄 개발자 이메일 (쉼표로 구분)
    'dev_emails' => '',

    // Xsolla Login 프로젝트 ID (토큰의 xsolla_login_project_id와 같아야 함)
    'login_project_id' => '30375188-893b-4b1a-ba1b-c8ba692fa5e3',

    // 게임 주소 (쉼표로 구분). 여기 있는 주소의 브라우저 요청만 허용
    'allowed_origins' => 'https://hucksoon.github.io',

    // DB. 기본은 SQLite 파일 (웹에서 열 수 없는 폴더에 두세요). MySQL 예:
    //   'db_dsn' => 'mysql:host=localhost;dbname=dungeon;charset=utf8mb4', 'db_user' => '...', 'db_pass' => '...',
    'db_dsn' => 'sqlite:' . __DIR__ . '/data/ranking.sqlite',
    'db_user' => null,
    'db_pass' => null,

    // Xsolla 공개 키 캐시 폴더 (쓰기 가능해야 함)
    'cache_dir' => __DIR__ . '/data',

    // (선택) Login 프로젝트가 HS256으로 서명하면 그 비밀 키. 비워 두면 Xsolla에 직접 확인을 요청
    'jwt_secret' => '',

    // 거래소: Xsolla Store(퍼블리셔) 프로젝트 ID와 프로젝트 API 키
    //   구매할 때 캐시 차감은 구매자 토큰으로 하므로 API 키가 필요 없지만,
    //   판매 대금(캐시)을 판매자에게 지급하려면 API 키가 필요해요 (Publisher Account → 프로젝트 설정 → API 키)
    'xsolla_project_id' => 317991,
    'xsolla_api_key' => '',
    'cash_sku' => 'cash',

    // 광고 보상: 광고를 시작하고 최소 몇 초가 지나야 보상을 줄지 (광고를 안 보고 바로 보상을 받는 걸 막음)
    'ad_min_seconds' => 5,
];
