// All date filters use the first publication date in Japan, never the edit date.
const months=new Map();
export function japanMonth(value){
 if(!value)return '';
 if(months.has(value))return months.get(value);
 const stamp=Date.parse(value),result=Number.isFinite(stamp)?new Date(stamp+9*60*60*1000).toISOString().slice(0,7):'';
 months.set(value,result);return result;
}
export function matchesPeriod(date,year='',month=''){
 if(!year&&!month)return true;
 const key=japanMonth(date);return !!key&&(!year||key.slice(0,4)===year)&&(!month||key.slice(5,7)===month);
}
export function periodYears(dates){return [...new Set(dates.map(japanMonth).filter(Boolean).map(x=>x.slice(0,4)))].sort().reverse();}
export function mountDateFilter(root,getDates,onChange){
 const query=new URLSearchParams(location.search);
 let year=/^\d{4}$/.test(query.get('year')||'')?query.get('year'):'',month=/^(0[1-9]|1[0-2])$/.test(query.get('month')||'')?query.get('month'):'';
 root.innerHTML='<legend>投稿日で絞り込む</legend><label>年 <select aria-label="投稿年"><option value="">すべての年</option></select></label><label>月 <select aria-label="投稿月"><option value="">すべての月</option></select></label><button type="button">年月を解除</button><span class="hint">初出投稿日・日本時間</span>';
 const [yearSelect,monthSelect]=root.querySelectorAll('select'),clear=root.querySelector('button');
 for(let n=1;n<=12;n++)monthSelect.add(new Option(n+'月',String(n).padStart(2,'0')));
 monthSelect.value=month;
 function syncURL(){const u=new URL(location.href);for(const [k,v]of [['year',year],['month',month]])v?u.searchParams.set(k,v):u.searchParams.delete(k);history.replaceState(null,'',u);}
 function changed(){year=yearSelect.value;month=monthSelect.value;clear.disabled=!year&&!month;syncURL();onChange();}
 yearSelect.onchange=changed;monthSelect.onchange=changed;
 clear.onclick=()=>{yearSelect.value='';monthSelect.value='';changed();};
 let signature='';
 const api={
  matches:date=>matchesPeriod(date,year,month),key:()=>year+'-'+month,
  active:()=>!!(year||month),label:()=>[year?year+'年':'',month?Number(month)+'月':''].join(''),
  clear(){year='';month='';yearSelect.value='';monthSelect.value='';clear.disabled=true;syncURL();},
  withPeriod(path){const u=new URL(path,location.origin);if(year)u.searchParams.set('year',year);if(month)u.searchParams.set('month',month);return u.pathname+u.search;},
  refresh(){const years=periodYears(getDates());if(year&&!years.includes(year))years.push(year);years.sort().reverse();const next=years.join(',');if(next!==signature){signature=next;yearSelect.replaceChildren(new Option('すべての年',''),...years.map(y=>new Option(y+'年',y)));}yearSelect.value=year;clear.disabled=!year&&!month;}
 };
 api.refresh();return api;
}
