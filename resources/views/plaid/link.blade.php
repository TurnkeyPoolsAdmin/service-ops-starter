<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Connect your bank — Service Ops</title>
    <script src="https://cdn.plaid.com/link/v2/stable/link-initialize.js"></script>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background:#f8fafc; color:#0f172a;
               display:flex; min-height:100vh; align-items:center; justify-content:center; margin:0; }
        .card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:2rem 2.5rem;
                box-shadow:0 1px 3px rgba(0,0,0,.08); text-align:center; max-width:420px; }
        h1 { font-size:1.1rem; margin:0 0 .5rem; }
        p { color:#475569; font-size:.9rem; margin:.25rem 0; }
        a { color:#2563eb; }
        .spinner { width:28px; height:28px; border:3px solid #e2e8f0; border-top-color:#2563eb;
                   border-radius:50%; animation:spin 1s linear infinite; margin:1rem auto; }
        @keyframes spin { to { transform:rotate(360deg); } }
    </style>
</head>
<body>
    <div class="card">
        <h1 id="title">Opening your bank…</h1>
        <div class="spinner"></div>
        <p id="status">A secure Plaid window is opening. Choose Chase and log in.</p>
        <p style="margin-top:1rem;"><a href="{{ $integrationsUrl }}">Cancel and go back</a></p>
    </div>

    <script>
        const linkToken = @json($linkToken);
        const exchangeUrl = @json($exchangeUrl);
        const integrationsUrl = @json($integrationsUrl);
        const isOauthReturn = @json($isOauthReturn);
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        function setStatus(title, msg) {
            document.getElementById('title').textContent = title;
            document.getElementById('status').textContent = msg;
        }

        const config = {
            token: linkToken,
            onSuccess: async (publicToken, metadata) => {
                setStatus('Linking…', 'Saving the connection — almost done.');
                try {
                    const res = await fetch(exchangeUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                        body: JSON.stringify({
                            public_token: publicToken,
                            institution_name: metadata?.institution?.name ?? null,
                            institution_id: metadata?.institution?.institution_id ?? null,
                        }),
                    });
                    const data = await res.json();
                    window.location.href = (data && data.redirect) ? data.redirect : integrationsUrl;
                } catch (e) {
                    setStatus('Something went wrong', 'Could not save the connection. Go back and try again.');
                }
            },
            onExit: (err) => {
                window.location.href = integrationsUrl;
            },
        };

        // On the OAuth bounce-back from the bank, Link must be re-initialised with
        // the original redirect URI so it can resume where it left off.
        if (isOauthReturn) {
            config.receivedRedirectUri = window.location.href;
        }

        const handler = Plaid.create(config);
        handler.open();
    </script>
</body>
</html>
