<?php

if (!function_exists('mikhmonCrudModalStart')) {
  function mikhmonCrudModalStart($closeUrl, $label = 'Form') {
    static $stylesPrinted = false;
    if (!$stylesPrinted) {
      $stylesPrinted = true;
      echo '<style>
        .crud-modal-layer{position:fixed;inset:0;z-index:1200;display:flex;align-items:flex-start;justify-content:center;padding:28px 16px;background:rgba(0,0,0,.58);overflow-y:auto;box-sizing:border-box}
        .crud-modal-dialog{position:relative;width:min(920px,100%);max-height:calc(100dvh - 56px);overflow-y:auto;overscroll-behavior:contain}
        .crud-modal-content>.row{margin:0}
        .crud-modal-content>.row>[class*=col-]{width:100%;max-width:100%;box-sizing:border-box}
        .crud-modal-content .card{margin-top:0;margin-bottom:0}
        .crud-modal-content .card-header{position:relative;min-height:52px;box-sizing:border-box;display:flex;align-items:center}
        .crud-modal-content .card-header h3{width:100%;padding-right:48px;box-sizing:border-box}
        .crud-modal-close{position:absolute;z-index:3;top:4px;right:4px;display:flex;align-items:center;justify-content:center;width:44px;height:44px;margin:0;padding:0;border:0;border-radius:3px;font-size:25px;line-height:1;text-align:center;cursor:pointer}
        .crud-form-actions{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;gap:8px;margin-top:16px;padding-top:12px;border-top:1px solid rgba(127,127,127,.25)}
        .crud-form-actions .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:38px;margin:0;box-sizing:border-box;white-space:normal}
        .crud-modal-layer :focus-visible{outline:2px solid #f5a623;outline-offset:2px}
        body.crud-modal-open{overflow:hidden}
        @media(max-width:750px){
          .crud-modal-layer{padding:12px 8px}
          .crud-modal-dialog{max-height:calc(100dvh - 24px)}
          .crud-modal-content .card-body{padding:12px}
          .crud-modal-content form .row>[class*=col-]{width:100%;max-width:100%;box-sizing:border-box}
        }
        @media(max-width:620px){
          .crud-modal-content form>.table,.crud-modal-content form>.table>tbody,.crud-modal-content form>.table>tbody>tr,.crud-modal-content form>.table>tbody>tr>td{display:block;width:100%;box-sizing:border-box}
          .crud-modal-content form>.table>tbody>tr{padding:5px 0}
          .crud-modal-content form>.table>tbody>tr>td{padding:5px;border:0}
          .crud-modal-content form>.table>tbody>tr>td:first-child{font-weight:600}
          .crud-modal-content .btn{min-height:44px}
          .crud-form-actions{align-items:stretch;flex-direction:column}
          .crud-form-actions .btn{width:100%;min-height:44px}
        }
      </style>';
    }
    echo '<div class="crud-modal-layer" data-crud-modal data-close-url="' . htmlspecialchars((string) $closeUrl, ENT_QUOTES) . '" role="dialog" aria-modal="true" aria-label="' . htmlspecialchars((string) $label, ENT_QUOTES) . '"><div class="crud-modal-dialog" role="document"><button class="btn bg-danger crud-modal-close" type="button" aria-label="Tutup modal">&times;</button><div class="crud-modal-content">';
  }
}

if (!function_exists('mikhmonCrudModalEnd')) {
  function mikhmonCrudModalEnd() {
    echo '</div></div></div><script>
      (function(){
        var modal=document.querySelector("[data-crud-modal]");
        if(!modal)return;
        var closeButton=modal.querySelector(".crud-modal-close");
        var cardHeader=modal.querySelector(".crud-modal-content .card-header");
        if(cardHeader)cardHeader.appendChild(closeButton);
        var closeUrl=modal.getAttribute("data-close-url");
        var closeModal=function(){window.location.assign(closeUrl);};
        document.body.classList.add("crud-modal-open");
        closeButton.addEventListener("click",closeModal);
        modal.addEventListener("click",function(event){if(event.target===modal)closeModal();});
        document.addEventListener("keydown",function(event){
          if(event.key==="Escape"){event.preventDefault();closeModal();return;}
          if(event.key!=="Tab")return;
          var items=Array.prototype.filter.call(modal.querySelectorAll("button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),a[href]"),function(item){return item.offsetParent!==null;});
          if(!items.length)return;
          var first=items[0],last=items[items.length-1];
          if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
          else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
        });
        window.setTimeout(function(){var firstField=modal.querySelector("input:not([type=hidden]):not([disabled]),select:not([disabled]),textarea:not([disabled])");(firstField||closeButton).focus();},0);
      })();
    </script>';
  }
}
