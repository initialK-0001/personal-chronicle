import test from 'node:test';
import assert from 'node:assert/strict';
import {timelineHTML,listExcerpt,blockHTML} from '../ui/timeline.js';
const post={id:'1',title:'',tags:[],blocks:[{type:'paragraph',text:'最初\n\n次の行'},{type:'heading',text:'見出し'},{type:'paragraph',text:'本文'}]};
test('一覧は改行とブロックを空白でつなぎ 個別本文は保持',()=>{assert.equal(listExcerpt(post),'最初 次の行 見出し 本文');assert.match(timelineHTML(post,'/?post=1'),/<p>最初 次の行 見出し 本文<\/p>/);assert.match(blockHTML(post.blocks[0]),/最初\n\n次の行/)});
test('画像なしも140文字まで 絵文字を壊さない',()=>{const p={...post,blocks:[{type:'paragraph',text:'👨‍👩‍👧‍👦'.repeat(141)}]};assert.equal(listExcerpt(p),'👨‍👩‍👧‍👦'.repeat(140));assert.doesNotMatch(timelineHTML(p,'/'),/<img|NoImage/)});
test('画像1枚の短文は画像を表示 管理操作は高さ制限の外',()=>{const p={...post,title:'記事',blocks:[...post.blocks,{type:'image',src:'/photo.png'}]};const html=timelineHTML(p,'/',true);assert.equal((html.match(/<img /g)||[]).length,1);assert.match(html,/<\/div><\/div><div class="post-management">/);assert.match(timelineHTML({...p,title:''},'/'),/<figure><img/)});
