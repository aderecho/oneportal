<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Opening {{ $provider->name }} - OnePortal</title>
        <style>
            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                background: #f8fafc;
                color: #111827;
                font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            }

            main {
                width: min(92vw, 460px);
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                background: #ffffff;
                padding: 28px;
                box-shadow: 0 18px 40px rgb(15 23 42 / 8%);
            }

            h1 {
                margin: 0 0 10px;
                font-size: 22px;
                line-height: 1.2;
            }

            p {
                margin: 0 0 20px;
                color: #4b5563;
                line-height: 1.6;
            }

            button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 42px;
                border: 0;
                border-radius: 6px;
                background: #7f1d1d;
                color: #ffffff;
                cursor: pointer;
                font: inherit;
                font-weight: 700;
                padding: 0 18px;
            }
        </style>
    </head>
    <body>
        <main>
            <h1>Opening {{ $provider->name }}</h1>
            <p>OnePortal is sending your secure sign-in response.</p>
            <form method="POST" action="{{ $acsUrl }}">
                <input type="hidden" name="SAMLResponse" value="{{ $samlResponse }}">
                @if ($relayState)
                    <input type="hidden" name="RelayState" value="{{ $relayState }}">
                @endif
                <button type="submit">Continue</button>
            </form>
        </main>
        <script>
            document.forms[0].submit();
        </script>
    </body>
</html>
