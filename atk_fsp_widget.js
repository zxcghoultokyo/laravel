/**
 * ATK Free Shipping Progress v3.0
 * Pure JavaScript - NO OCMOD!
 * 
 * Add to footer.tpl before </body>:
 * <script src="catalog/view/javascript/atk_fsp_widget.js"></script>
 */
(function() {
    var CFG = {
        threshold: 20000,
        symbol: 'грн',
        rate: 40,
        txtProgress: 'До безкоштовної доставки залишилось <b>{remaining}</b>',
        txtDone: '🎉 Ви отримали <b>безкоштовну доставку!</b>',
        selectors: ['.ocmodpcart-content','.ocmodpcart-products','#cart .dropdown-menu']
    };
    
    var CSS = '.atk-fsp{padding:12px;margin:0 0 10px;background:#f5f5f5;border-radius:8px}.atk-fsp .t{margin-bottom:8px;font-size:13px;color:#333}.atk-fsp .w{background:#ddd;border-radius:8px;height:10px;overflow:hidden}.atk-fsp .b{height:100%;border-radius:8px;transition:width .4s}.atk-fsp .r{background:#dc3545}.atk-fsp .y{background:#ffc107}.atk-fsp .g{background:#28a745}.atk-fsp .p{font-size:11px;color:#888;margin-top:4px;text-align:right}';
    
    function fmt(v){return Math.round(v).toLocaleString('uk-UA')+' '+CFG.symbol}
    
    function getTotal(){
        var el=document.querySelector('.ocmodpcart-total,#cart-total');
        if(el){
            var m=(el.textContent||'').replace(/\s/g,'').match(/([\d]+)/);
            if(m){var v=parseFloat(m[1]);if(v>0&&v<500)v*=CFG.rate;return v}
        }
        return 0;
    }
    
    function show(){
        var t=getTotal();if(t<=0)return;
        var old=document.getElementById('atk-fsp');if(old)old.remove();
        var box=null;
        for(var i=0;i<CFG.selectors.length;i++){box=document.querySelector(CFG.selectors[i]);if(box)break}
        if(!box)return;
        var rem=Math.max(0,CFG.threshold-t),pct=Math.min(100,t/CFG.threshold*100);
        var c=pct>=100?'g':(pct>=70?'y':'r');
        var txt=rem>0?CFG.txtProgress.replace('{remaining}',fmt(rem)):CFG.txtDone;
        var h='<div class="atk-fsp" id="atk-fsp"><div class="t">'+txt+'</div><div class="w"><div class="b '+c+'" style="width:'+pct+'%"></div></div><div class="p">'+Math.round(pct)+'%</div></div>';
        box.insertAdjacentHTML('afterbegin',h);
    }
    
    function init(){
        var s=document.createElement('style');s.textContent=CSS;document.head.appendChild(s);
        setInterval(function(){
            var cart=document.querySelector('.ocmodpcart-content,#cart .dropdown-menu');
            if(cart&&cart.offsetParent!==null)show();
        },500);
    }
    
    document.readyState==='loading'?document.addEventListener('DOMContentLoaded',init):init();
})();
