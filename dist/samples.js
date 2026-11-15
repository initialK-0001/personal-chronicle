// 試作室の表示確認用データ 保存済み投稿や公開APIには混入させない
const id=()=>crypto.randomUUID();
const textBlock=text=>({id:id(),type:"paragraph",text});
const imgBlock=(src,alt)=>({id:id(),type:"image",src,alt});
export const samples=[
{id:'sample-article',title:'VRM1.0の表情について',tags:['VRM','技術記事'],date:'2022-10-23T11:34',sample:true,blocks:[imgBlock('assets/expression.jpg','VRM1.0の表情'),textBlock('VRM1.0の表情操作についての既存記事'),{id:id(),type:'heading',text:'表情の設定'},textBlock('プリセットの表情とカスタムの表情を扱う記事の表示見本'),{id:id(),type:'link',text:'元の記事を読む',url:'https://brand-new.tokyo/vrm1-expression'}]},
{id:'sample-pose',title:'基本立ちポーズ',tags:['VRM','ポーズ'],date:'2022-05-01T12:00',sample:true,blocks:[imgBlock('assets/pose.png','基本立ちポーズ')]},
{id:'sample-gallery',title:'ピースポーズと立ちポーズ',tags:['VRM','ポーズ'],date:'2022-05-01T11:00',sample:true,blocks:[imgBlock('assets/peace.png','Wピース'),imgBlock('assets/style.png','立ちポーズ')]},
{id:'sample-cover',title:'VRMお手軽ポーズ用ポーズ集',tags:['VRM','ポーズ'],date:'2022-05-01T10:00',sample:true,blocks:[imgBlock('assets/cover.jpg','ポーズ集'),textBlock('VRMお手軽ポーズ用のポーズをまとめたページ'),{id:id(),type:'link',text:'元の記事を読む',url:'https://brand-new.tokyo/vrm_otegaru_poses_01'}]},
{id:'sample-note',title:'',tags:['メモ'],date:'2022-04-30T12:00',sample:true,blocks:[textBlock('短文投稿の表示見本\n\nタイトルなしでも本文から表示\n画像や文章はあとから追加可能')]},
{id:'sample-thought',title:'制作記録の表示見本',tags:['制作記録'],date:'2022-04-29T10:00',sample:true,blocks:[textBlock('画像と文章を同じ投稿に保存'),{id:id(),type:'heading',text:'記録する項目'},textBlock('使用したモデル\nポーズと表情\n撮影設定\n変更した箇所') ]}
];
