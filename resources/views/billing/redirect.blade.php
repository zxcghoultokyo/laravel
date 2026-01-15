<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Перенаправлення на оплату...</title>
</head>
<body onload="document.forms['payment_form'].submit();">
    <p style="text-align: center; padding: 50px; font-family: sans-serif;">
        Перенаправлення на сторінку оплати...<br>
        <small>Якщо перенаправлення не спрацювало, <a href="#" onclick="document.forms['payment_form'].submit(); return false;">натисніть тут</a>.</small>
    </p>
    
    <form name="payment_form" action="{{ $url }}" method="POST" style="display: none;">
        @foreach($data as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach
        <button type="submit">Оплатити</button>
    </form>
</body>
</html>
