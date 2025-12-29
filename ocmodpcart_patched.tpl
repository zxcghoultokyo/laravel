<div id="ocmod-popup-okno">
<div id="ocmod-popup-okno-inner">
<?php if ($products) { ?>
  <div class="ocmod-popup-heading"><?php echo $heading_cartpopup_title; ?></div>
  <div class="ocmod-popup-center">
    <?php if ($attention) { ?>
    <div class="alert alert-info"><i class="fa fa-info-circle"></i> <?php echo $attention; ?>
      <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php } ?>
    <?php if ($success) { ?>
    <div class="alert alert-success"><i class="fa fa-check-circle"></i> <?php echo $success; ?>
      <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php } else { ?>
    <div id="success-message"></div>
    <?php } ?>
    <?php if ($error_warning) { ?>
    <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?php echo $error_warning; ?>
      <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php } ?>
    <table class="display-products-cart">
      <tbody>
        <?php foreach ($products as $product) { ?>
        <tr>
          <td class="image">
            <?php if ($product['thumb']) { ?>
            <a href="<?php echo $product['href']; ?>"><img src="<?php echo $product['thumb']; ?>" alt="<?php echo $product['name']; ?>" title="<?php echo $product['name']; ?>" class="img-thumbnail" /></a>
            <?php } ?>
          </td>
          <td class="name">
            <a href="<?php echo $product['href']; ?>"><?php echo $product['name']; ?></a>
            <?php if (!$product['stock']) { ?>
            <span class="text-danger">***</span>
            <?php } ?>
            <?php if ($product['option']) { ?>
            <?php foreach ($product['option'] as $option) { ?>
            <br />
            <?php echo $option['name']; ?>: <?php echo $option['value']; ?>
            <?php } ?>
            <?php } ?>
            <?php if ($product['reward']) { ?>
            <br />
            <?php echo $product['reward']; ?>
            <?php } ?>
          </td>
          <td class="qt">
            <div class="number">
              <input name="product_id" value="<?php echo $product['key']; ?>" style="display: none;" type="hidden" />
              <div class="frame-change-count">
                <div class="btn-plus">
                  <button type="button" onclick="$(this).parent().parent().next().val(~~$(this).parent().parent().next().val()+1); update( this, 'update' );">
                    +
                  </button>
                </div>
                <div class="btn-minus">
                  <button type="button" onclick="$(this).parent().parent().next().val(~~$(this).parent().parent().next().val()-1); update( this, 'update' );">
                    -
                  </button>
                </div>
              </div>
              <input type="text" name="quantity" value="<?php echo $product['quantity']; ?>" class="plus-minus" onchange="update_manual( this, '<?php echo $product['key']; ?>' ); return validate(this);" onkeyup="update_manual( this, '<?php echo $product['key']; ?>' ); return validate(this);" />
            </div>
          </td>
          <td class="totals"><?php echo $product['total']; ?></td>
          <td class="remove">
            <button type="button" onclick="update( this, 'remove' );"><i class="fa fa-trash-o"></i></button>
            <input name="product_key" value="<?php echo $product['key']; ?>" style="display: none;" hidden />           
          </td>
        </tr>
        <?php } ?>
      </tbody>
    </table>
    <div class="mobile-products-cart">
    <?php foreach ($products as $product) { ?>
      <div>
        <div class="image">
          <?php if ($product['thumb']) { ?>
          <a href="<?php echo $product['href']; ?>"><img src="<?php echo $product['thumb']; ?>" alt="<?php echo $product['name']; ?>" title="<?php echo $product['name']; ?>" class="img-thumbnail" /></a>
          <?php } ?>
        </div>
        <div class="name">
          <a href="<?php echo $product['href']; ?>"><?php echo $product['name']; ?></a>
          <?php if (!$product['stock']) { ?>
          <span class="text-danger">***</span>
          <?php } ?>
          <?php if ($product['option']) { ?>
          <?php foreach ($product['option'] as $option) { ?>
          <br />
          <?php echo $option['name']; ?>: <?php echo $option['value']; ?>
          <?php } ?>
          <?php } ?>
          <?php if ($product['reward']) { ?>
          <br />
          <?php echo $product['reward']; ?>
          <?php } ?>
        </div>
        <div class="qt">
          <div class="number">
              <input name="product_id" value="<?php echo $product['key']; ?>" style="display: none;" type="hidden" />
              <div class="frame-change-count">
                <div class="btn-plus">
                  <button type="button" onclick="$(this).parent().parent().next().val(~~$(this).parent().parent().next().val()+1); update( this, 'update' );">
                    +
                  </button>
                </div>
                <div class="btn-minus">
                  <button type="button" onclick="$(this).parent().parent().next().val(~~$(this).parent().parent().next().val()-1); update( this, 'update' );">
                    -
                  </button>
                </div>
              </div>
              <input type="text" name="quantity" value="<?php echo $product['quantity']; ?>" class="plus-minus" onchange="update_manual( this, '<?php echo $product['key']; ?>' ); return validate(this);" onkeyup="update_manual( this, '<?php echo $product['key']; ?>' ); return validate(this);" />
            </div>
            <span class="remove">
              <button type="button" onclick="update( this, 'remove' );"><i class="fa fa-trash-o"></i></button>
              <input name="product_key" value="<?php echo $product['key']; ?>" style="display: none;" hidden />
            </span>
        </div>
        <div class="totals">
          <?php echo $product['total']; ?>
        </div>
        </div>
      <?php } ?>
    </div>
    <div class="all-total">
      <?php foreach ($totals as $total) { ?>
        <div class="clear-total">
        <div class="totals-right"><?php echo $total['text']; ?></div>
        <div class="totals-left"><?php echo $total['title']; ?>:</div>     
        </div>
      <?php } ?>
    </div>    
  </div>
  
  <?php if (isset($fsp_show) && $fsp_show) { ?>
  <style>
  .atk-fsp{padding:15px;margin:20px 15px 15px;background:#f5f5f5;border-radius:8px;border-top:1px solid #e0e0e0}
  .atk-fsp-row{display:flex;align-items:center;gap:12px;margin-bottom:12px}
  .atk-fsp-icon{flex-shrink:0;width:40px;height:40px;background:#4a7c59;border-radius:50%;display:flex;align-items:center;justify-content:center}
  .atk-fsp-icon svg{fill:#fff}
  .atk-fsp-text{font-size:14px;line-height:1.4;color:#333}
  .atk-fsp-text b{color:#c41e3a}
  .atk-fsp-scale-wrap{position:relative;margin-bottom:8px;padding-top:12px}
  .atk-fsp-scale{height:8px;background:#ddd;border-radius:4px;overflow:hidden}
  .atk-fsp-scale-bar{height:100%;background:linear-gradient(90deg,#c41e3a,#e85d75);border-radius:4px;transition:width 0.5s}
  .atk-fsp-scale-icons{position:absolute;top:6px;left:0;right:0;display:flex;justify-content:space-between;pointer-events:none}
  .atk-fsp-scale-icon{width:20px;height:20px;background:#c41e3a;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 3px rgba(0,0,0,0.2)}
  .atk-fsp-scale-icon svg{fill:#fff;width:12px;height:12px}
  .atk-fsp-prices{display:flex;justify-content:space-between;font-size:13px;color:#666;margin-top:8px}
  .atk-fsp-prices sup{font-size:10px}
  .atk-fsp-success .atk-fsp-scale-bar{background:linear-gradient(90deg,#28a745,#5cb85c)}
  .atk-fsp-success .atk-fsp-icon,.atk-fsp-success .atk-fsp-scale-icon{background:#28a745}
  .atk-fsp-success .atk-fsp-text b{color:#28a745}
  </style>
  <div class="atk-fsp <?php echo $fsp_achieved ? 'atk-fsp-success' : ''; ?>">
    <div class="atk-fsp-row">
      <div class="atk-fsp-icon">
        <svg width="24" height="24" viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm13.5-9l1.96 2.5H17V9.5h2.5zm-1.5 9c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
      </div>
      <div class="atk-fsp-text">
        <?php if ($fsp_achieved) { ?>
          🎉 Вітаємо! Ви отримали <b>безкоштовну доставку!</b>
        <?php } else { ?>
          Замов ще на <b><?php echo $fsp_remaining_fmt; ?> <sup>грн</sup></b> та отримай безкоштовну доставку
        <?php } ?>
      </div>
    </div>
    <div class="atk-fsp-scale-wrap">
      <div class="atk-fsp-scale-icons">
        <div class="atk-fsp-scale-icon">
          <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14l-5-5 1.41-1.41L12 14.17l4.59-4.58L18 11l-6 6z"/></svg>
        </div>
        <div class="atk-fsp-scale-icon">
          <svg viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4z"/></svg>
        </div>
      </div>
      <div class="atk-fsp-scale">
        <div class="atk-fsp-scale-bar" style="width:<?php echo $fsp_percent; ?>%"></div>
      </div>
    </div>
    <div class="atk-fsp-prices">
      <div><?php echo $fsp_subtotal_fmt; ?> <sup>грн</sup></div>
      <div><?php echo $fsp_threshold_fmt; ?> <sup>грн</sup></div>
    </div>
  </div>
  <?php } ?>
  
  <div class="ocmod-popup-footer">
    <button onclick="$.magnificPopup.close();"><?php echo $button_shopping; ?></button>
    <a href="<?php echo $checkout_link; ?>"><?php echo $button_checkout; ?></a>
  </div>
<?php } else { ?>
  <div class="ocmod-popup-heading"><?php echo $heading_cartpopup_title_empty; ?></div>
  <div class="ocmod-popup-center empty-cart"><?php echo $text_cartpopup_empty; ?></div>
  <div class="ocmod-popup-footer">
    <button onclick="$.magnificPopup.close();"><?php echo $button_shopping; ?></button>
  </div>
<?php } ?>
</div>
<script type="text/javascript"><!--
function masked(element, status) {
  if (status == true) {
    $('<div/>').attr({ 'class':'masked' }).prependTo(element);
    $('<div class="masked_loading" />').insertAfter($('.masked'));
  } else {
    $('.masked').remove();
    $('.masked_loading').remove();
  }
}

function validate( input ) {
  input.value = input.value.replace( /[^\d,]/g, '' );
}

function update( target, status ) {
  masked('#ocmod-popup-okno-inner', true);
  var input_val    = $( target ).parent().parent().parent().children( 'input[name=quantity]' ).val(),
      quantity     = parseInt( input_val ),
      product_id   = $( target ).parent().parent().parent().children( 'input[name=product_id]' ).val(),
      product_key  = $( target ).next().val(),
      urls         = null;

  if ( quantity <= 0 ) {
    masked('#ocmod-popup-okno-inner', false);
    quantity = $( target ).parent().parent().parent().children( 'input[name=quantity]' ).val( 1 );
    return;
  }

  if ( status == 'update' ) {
    urls = 'index.php?route=extension/module/ocmodpcart&update=' + product_id + '&quantity=' + quantity;
  } else if ( status == 'add' ) {
    urls = 'index.php?route=extension/module/ocmodpcart&add=' + target + '&quantity=1';
  } else {
    urls = 'index.php?route=extension/module/ocmodpcart&remove=' + product_key;
  }
      
  $.ajax({
    url: urls,
    type: 'get',
    dataType: 'html',
    success: function( data ) {
      $.ajax({
        url: 'index.php?route=extension/module/ocmodpcart/status_cart',
        type: 'get',
        dataType: 'json',
        success: function( json ) {
          masked('#ocmod-popup-okno-inner', false);
          if (json['total']) {
            $('#cart-total' ).html(json['total']);
            $('#cart > ul').load('index.php?route=common/cart/info ul li');
          }
          $('#ocmod-popup-okno-inner').html( $( data ).find( '#ocmod-popup-okno-inner > *' ) );
        } 
      });
    } 
  });
}
function update_manual( target, product_id ) {
  masked('#ocmod-popup-okno-inner', true);
  var input_val = $( target ).val(),
      quantity  = parseInt( input_val );
    
  if ( quantity <= 0 ) {
    masked('#ocmod-popup-okno-inner', false);
    quantity = $( target ).val( 1 );
    return;
  }
  
  $.ajax({
    url: 'index.php?route=extension/module/ocmodpcart&update=' + product_id + '&quantity=' + quantity,
    type: 'get',
    dataType: 'html',
    success: function( data ) {
      $.ajax({
        url: 'index.php?route=extension/module/ocmodpcart/status_cart',
        type: 'get',
        dataType: 'json',
        success: function( json ) {
          masked('#ocmod-popup-okno-inner', false);
          if (json['total']) {
            $('#cart-total' ).html(json['total']);
            $('#cart > ul').load('index.php?route=common/cart/info ul li');
          }
          $('#ocmod-popup-okno-inner').html( $( data ).find( '#ocmod-popup-okno-inner > *' ) );
        } 
      });
    } 
  });
}
//--></script>
</div>
