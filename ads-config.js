// 광고 설정
//
// 지금은 테스트 광고로 동작해요: 광고 대신 5초짜리 안내 화면이 뜨고, 다 보면 보상을 받아요 (수익 없음).
//
// 구글 AdSense "H5 게임 광고(보상형)" 승인을 받으면 아래처럼 바꿔요:
//   window.ADS = { provider: "h5", client: "ca-pub-0000000000000000", test: false };
//   - client: AdSense 게시자 ID (ca-pub-로 시작)
//   - test: true로 두면 구글의 테스트 광고가 떠요 (승인 전 확인용)
// 광고 상품·정책: 보상형 광고는 H5 게임 광고(Ad Placement API)로만 써야 해요. 일반 배너를 "보면 보상"으로 쓰면 정책 위반이에요.
window.ADS = { provider: "test" };
