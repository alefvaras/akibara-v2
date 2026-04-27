(function(){
'use strict';
if (typeof AKB_INV === 'undefined') return;

var A = AKB_INV.ajaxUrl;
var N = AKB_INV.nonce;
var I = AKB_INV.i18n || {};
var S = {p:1,pp:50,search:'',status:'all',manage:'all',serie:0,editorial:'',orderby:'title',order:'ASC',sel:{},restock:false};
var LP = {p:1};

function $(id){return document.getElementById(id)}
function h(s){var d=document.createElement('span');d.textContent=s||'';return d.innerHTML}
function msg(key, fallback){return I[key] || fallback}

function showAlert(text, type){
  var el = $('akb-inv-alert');
  if(!el) return;
  el.className = 'akb-inv-alert akb-inv-alert--' + (type || 'error');
  el.textContent = text;
  el.style.display = '';
}
function hideAlert(){
  var el = $('akb-inv-alert');
  if(!el) return;
  el.style.display = 'none';
}

function rq(act,data){
  data.action = act;
  data.nonce = N;
  return fetch(A, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams(data)
  }).then(function(res){
    if(!res.ok) throw new Error(msg('networkError','Error de red.'));
    return res.json();
  }).then(function(json){
    if(!json.success){
      var m = (json.data && json.data.message) ? json.data.message : msg('genericError','Error inesperado.');
      throw new Error(m);
    }
    return json;
  });
}

function statusBadge(p){
  if(p.stock_status==='outofstock') return {cls:'inv-b-out',label:'Agotado'};
  if(p.stock_status==='onbackorder') return {cls:'inv-b-bo',label:'Backorder'};
  var low = parseInt(p.low_stock_amount, 10) || 0;
  if(p.stock!==null && low>0 && p.stock<=low && p.stock>0) return {cls:'inv-b-low',label:'Bajo'};
  return {cls:'inv-b-in',label:'En stock'};
}

function stats(){
  rq('akb_inv_stats',{}).then(function(r){
    hideAlert();
    var d=r.data;
    $('s-total').textContent=(d.total||0).toLocaleString('es-CL');
    $('s-in').textContent=(d.instock||0).toLocaleString('es-CL');
    $('s-out').textContent=(d.outofstock||0).toLocaleString('es-CL');
    $('s-low').textContent=(d.low||0).toLocaleString('es-CL');
    $('s-nm').textContent=(d.no_manage||0).toLocaleString('es-CL');
    $('s-val').textContent='$'+Math.round(d.val||0).toLocaleString('es-CL');
  }).catch(function(err){showAlert(err.message,'error')});
}

function row(p){
  var st = statusBadge(p);
  var stockVal = p.manage_stock ? (p.stock ?? 0) : '—';
  var thumb = p.thumb ? '<img src="'+h(p.thumb)+'" class="inv-thumb" loading="lazy">' : '<span class="inv-thumb"></span>';
  var serie = p.serie ? '<div class="inv-serie">'+h(p.serie)+(p.tomo?(' #'+p.tomo):'')+'</div>' : '';
  var stockCell;
  if(!p.manage_stock){
    stockCell = '<span class="inv-no-manage">sin gestión</span>';
  }else if(S.restock){
    stockCell = '<span class="inv-stock"><span class="inv-stock-val">'+stockVal+'</span> <span style="color:var(--akb-text-muted);margin:0 2px">+</span> <input class="inv-restock-input js-restock" data-id="'+p.id+'" type="number" min="0" value="" placeholder="0"></span>';
  }else{
    stockCell = '<span class="inv-stock"><button class="inv-pm js-pm" data-id="'+p.id+'" data-d="-1">−</button><span class="inv-stock-val js-stk-edit" data-id="'+p.id+'">'+stockVal+'</span><button class="inv-pm js-pm" data-id="'+p.id+'" data-d="1">+</button></span>';
  }

  return '<tr data-id="'+p.id+'">'
    +'<td><input type="checkbox" class="js-cb" data-id="'+p.id+'"></td>'
    +'<td>'+thumb+'</td>'
    +'<td><div class="inv-title"><a href="'+h(p.edit_url)+'" target="_blank">'+h(p.title)+'</a></div>'+serie+'</td>'
    +'<td><span class="inv-ed">'+h(p.editorial||'')+'</span></td>'
    +'<td>$'+Math.round(p.price||0).toLocaleString('es-CL')+'</td>'
    +'<td>'+stockCell+'</td>'
    +'<td><span class="inv-b '+st.cls+'">'+st.label+'</span></td>'
    +'<td><label class="inv-tog"><input type="checkbox" '+(p.manage_stock?'checked':'')+' class="js-manage" data-id="'+p.id+'"><i></i><b></b></label></td>'
    +'</tr>';
}

function pag(d){
  var el=$('pg');
  if(d.pages<=1){el.innerHTML='<span>'+d.total+' productos</span>';return}
  var s='<span>'+(((d.page-1)*d.per_page)+1)+'-'+Math.min(d.page*d.per_page,d.total)+' de '+d.total+'</span><div class="akb-inv-pg-btns">';
  s+='<button class="pg-btn" data-p="'+(d.page-1)+'" '+(d.page<=1?'disabled':'')+'>‹</button>';
  for(var i=Math.max(1,d.page-2);i<=Math.min(d.pages,d.page+2);i++) s+='<button class="pg-btn'+(i===d.page?' on':'')+'" data-p="'+i+'">'+i+'</button>';
  s+='<button class="pg-btn" data-p="'+(d.page+1)+'" '+(d.page>=d.pages?'disabled':'')+'>›</button></div>';
  el.innerHTML=s;
}

function load(){
  var tb=$('tbody');
  tb.innerHTML='<tr><td colspan="8" class="inv-load">Cargando…</td></tr>';
  hideAlert();
  S.sel={};
  bulkBar();
  if($('sel-all')) $('sel-all').checked=false;

  rq('akb_inv_products',{
    page:S.p,per_page:S.pp,search:S.search,status:S.status,manage:S.manage,
    serie:S.serie,editorial:S.editorial,orderby:S.orderby,order:S.order
  }).then(function(r){
    var d=r.data;
    if(!d.products.length){tb.innerHTML='<tr><td colspan="8" class="inv-empty">Sin resultados para estos filtros</td></tr>';pag(d);return}
    tb.innerHTML=d.products.map(row).join('');
    pag(d);
  }).catch(function(err){
    tb.innerHTML='<tr><td colspan="8" class="inv-empty">'+h(err.message)+'</td></tr>';
    showAlert(err.message,'error');
  });
}

function bulkBar(){
  var keys=Object.keys(S.sel).filter(function(k){return S.sel[k]});
  $('bulk-n').textContent=keys.length;
  $('bulk').style.display=keys.length?'flex':'none';
}

function initEvents(){
  $('pg').addEventListener('click',function(e){
    var b=e.target.closest('[data-p]');
    if(!b||b.disabled) return;
    S.p=+b.dataset.p;
    load();
  });

  var timer;
  $('f-search').addEventListener('input',function(){clearTimeout(timer);var v=this.value;timer=setTimeout(function(){S.search=v;S.p=1;load()},300)});
  $('f-status').addEventListener('change',function(){S.status=this.value;S.p=1;load()});
  $('f-manage').addEventListener('change',function(){S.manage=this.value;S.p=1;load()});
  $('f-serie').addEventListener('change',function(){S.serie=+this.value;S.p=1;load()});
  $('f-ed').addEventListener('change',function(){S.editorial=this.value;S.p=1;load()});
  $('f-restock').addEventListener('change',function(){S.restock=this.checked;load()});

  $('tbl').querySelector('thead').addEventListener('click',function(e){
    var th=e.target.closest('[data-s]');
    if(!th) return;
    var c=th.dataset.s;
    if(S.orderby===c) S.order=S.order==='ASC'?'DESC':'ASC';
    else {S.orderby=c;S.order='ASC'}
    S.p=1;
    load();
  });

  $('tbody').addEventListener('click',function(e){
    var btn=e.target.closest('.js-pm');
    if(!btn) return;
    var id=btn.dataset.id,delta=+btn.dataset.d,row=btn.closest('tr'),val=row.querySelector('.inv-stock-val');
    row.classList.add('inv-saving');
    rq('akb_inv_update',{product_id:id,field:'increment',value:delta}).then(function(r){
      row.classList.remove('inv-saving');
      val.textContent=r.data.stock;
      row.classList.add('inv-flash');
      setTimeout(function(){row.classList.remove('inv-flash')},700);
      stats();
    }).catch(function(err){
      row.classList.remove('inv-saving');
      showAlert(err.message,'error');
    });
  });

  $('tbody').addEventListener('click',function(e){
    var el=e.target.closest('.js-stk-edit');
    if(!el||el.querySelector('input')) return;
    var cur=el.textContent.trim(),id=el.dataset.id;
    var inp=document.createElement('input');
    inp.type='number';inp.value=cur==='—'?'0':cur;inp.min='0';
    inp.style.cssText='width:50px;height:24px;text-align:center;border:1px solid var(--akb-brand);border-radius:3px;font-size:13px';
    el.textContent='';el.appendChild(inp);inp.focus();inp.select();

    function save(){
      var v=inp.value;
      el.textContent=cur;
      if(v===cur) return;
      var row=el.closest('tr');
      row.classList.add('inv-saving');
      rq('akb_inv_update',{product_id:id,field:'stock',value:v}).then(function(r){
        row.classList.remove('inv-saving');
        el.textContent=r.data.stock;
        row.classList.add('inv-flash');
        setTimeout(function(){row.classList.remove('inv-flash')},700);
        stats();
      }).catch(function(err){
        row.classList.remove('inv-saving');
        el.textContent=cur;
        showAlert(err.message,'error');
      });
    }
    inp.addEventListener('blur',save);
    inp.addEventListener('keydown',function(ev){if(ev.key==='Enter') inp.blur(); if(ev.key==='Escape'){inp.value=cur; inp.blur();}});
  });

  $('tbody').addEventListener('keydown',function(e){
    if(!e.target.classList.contains('js-restock')||e.key!=='Enter') return;
    e.preventDefault();
    var inp=e.target,id=inp.dataset.id,qty=parseInt(inp.value,10)||0;
    if(qty<=0) return;
    var row=inp.closest('tr');
    row.classList.add('inv-saving');
    rq('akb_inv_update',{product_id:id,field:'increment',value:qty}).then(function(r){
      row.classList.remove('inv-saving');
      row.querySelector('.inv-stock-val').textContent=r.data.stock;
      inp.value='';
      row.classList.add('inv-flash');
      setTimeout(function(){row.classList.remove('inv-flash')},700);
      var next=row.nextElementSibling;if(next){var ni=next.querySelector('.js-restock');if(ni)ni.focus()}
      stats();
    }).catch(function(err){
      row.classList.remove('inv-saving');
      showAlert(err.message,'error');
    });
  });

  $('tbody').addEventListener('change',function(e){
    if(!e.target.classList.contains('js-manage')) return;
    var cb=e.target,id=cb.dataset.id,v=cb.checked?'yes':'no',row=cb.closest('tr');
    row.classList.add('inv-saving');
    rq('akb_inv_update',{product_id:id,field:'manage_stock',value:v}).then(function(){
      row.classList.remove('inv-saving');
      load();
      stats();
    }).catch(function(err){
      row.classList.remove('inv-saving');
      cb.checked=!cb.checked;
      showAlert(err.message,'error');
    });
  });

  $('sel-all').addEventListener('change',function(){
    var checked=this.checked;
    document.querySelectorAll('.js-cb').forEach(function(cb){cb.checked=checked;S.sel[cb.dataset.id]=checked});
    bulkBar();
  });
  $('tbody').addEventListener('change',function(e){if(!e.target.classList.contains('js-cb'))return;S.sel[e.target.dataset.id]=e.target.checked;bulkBar()});
  $('bulk-act').addEventListener('change',function(){$('bulk-val').style.display=(this.value==='set_stock'||this.value==='add_stock')?'inline-block':'none'});

  $('bulk-go').addEventListener('click',function(){
    var act=$('bulk-act').value;
    if(!act){showAlert(msg('selectAction','Selecciona acción'),'error');return;}
    var ids=Object.keys(S.sel).filter(function(k){return S.sel[k]});
    if(!ids.length){showAlert(msg('noProducts','Sin productos seleccionados'),'error');return;}
    var val=$('bulk-val').value;
    if(!confirm((msg('confirmBulk','¿Aplicar a'))+' '+ids.length+' productos?')) return;

    var btn=this;
    btn.disabled=true;btn.textContent='…';
    rq('akb_inv_bulk',{bulk_action:act,product_ids:ids.join(','),value:val}).then(function(r){
      btn.disabled=false;btn.textContent='Aplicar';
      showAlert('✅ '+r.data.updated+' '+msg('updated','actualizados')+(r.data.failed?(' · '+r.data.failed+' con error'):''),'ok');
      load();
      stats();
    }).catch(function(err){
      btn.disabled=false;btn.textContent='Aplicar';
      showAlert(err.message,'error');
    });
  });

  document.querySelectorAll('.akb-inv-tab').forEach(function(t){
    t.addEventListener('click',function(){
      document.querySelectorAll('.akb-inv-tab').forEach(function(x){x.classList.remove('on')});
      t.classList.add('on');
      $('v-stock').style.display=t.dataset.v==='stock'?'':'none';
      $('v-log').style.display=t.dataset.v==='log'?'':'none';
      if(t.dataset.v==='log') loadLog();
    });
  });

  $('log-pg').addEventListener('click',function(e){var b=e.target.closest('.lp-btn');if(!b)return;LP.p=+b.dataset.p;loadLog()});
}

function loadLog(){
  var tb=$('log-tb');tb.innerHTML='<tr><td colspan="5" class="inv-load">Cargando…</td></tr>';
  rq('akb_inv_log',{page:LP.p}).then(function(r){
    hideAlert();
    if(!r.data.logs.length){tb.innerHTML='<tr><td colspan="5" class="inv-empty">Sin registros. Los cambios se guardan automáticamente.</td></tr>';return}
    tb.innerHTML=r.data.logs.map(function(l){
      var chg='';
      if(l.old!==null&&l.new!==null){var d=l.new-l.old;var c=d>0?'st-ok':d<0?'st-bad':'';chg=l.old+' → '+l.new+' <b class="'+c+'">'+(d>0?'+':'')+d+'</b>'}
      return '<tr><td style="white-space:nowrap">'+h(l.date)+'</td><td>'+h(l.product)+'</td><td>'+chg+'</td><td>'+h(l.reason)+'</td><td>'+h(l.user)+'</td></tr>';
    }).join('');
    var pe=$('log-pg');
    if(r.data.pages>1){
      var s='<span>'+r.data.total+' registros</span><div class="akb-inv-pg-btns">';
      for(var i=1;i<=Math.min(r.data.pages,10);i++) s+='<button class="pg-btn'+(i===r.data.page?' on':'')+' lp-btn" data-p="'+i+'">'+i+'</button>';
      s+='</div>';
      pe.innerHTML=s;
    }else pe.innerHTML='';
  }).catch(function(err){
    tb.innerHTML='<tr><td colspan="5" class="inv-empty">'+h(err.message)+'</td></tr>';
    showAlert(err.message,'error');
  });
}

if ($('akb-inv-root')) {
  initEvents();
  stats();
  load();
}
})();
