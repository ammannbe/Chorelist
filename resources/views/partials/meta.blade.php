{{--
    Description and link previews (Open Graph, X/Twitter). Fixed German texts: the app shell
    is cached by the service worker for every language, and link previews are crawled
    without a user. Image: public/og-image.png (1200×630).
--}}
@php($description = 'Ämtli in WG und Familie planen: wiederkehrende Aufgaben, Erinnerungen, Verlauf und Punkte-Ranking – funktioniert auch ohne Internet.')
<meta name="description" content="{{ $description }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:title" content="{{ config('app.name') }} – gemeinsame Ämtli, auch offline">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ url('/') }}">
<meta property="og:locale" content="de_CH">
<meta property="og:image" content="{{ url('/og-image.png') }}">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ config('app.name') }}: geteilte Ämtli mit Punkte-Ranking">
<meta name="twitter:card" content="summary_large_image">
