<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $profile['name'] }} · FILMUNITY</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit(($profile['headline'] ?? '') ?: $profile['bio'], 155) }}">
    <link rel="canonical" href="{{ route('community.profile', ['username' => $profile['username']]) }}">
    <link rel="stylesheet" href="{{ asset('brand/community-profile.css') }}?v=8">
    <script src="{{ asset('brand/community-profile.js') }}?v=2" defer></script>
</head>
<body>
    @php
        $industries = implode(' · ', [$profile['industry'], ...($profile['additional'] ?? [])]);
        $details = [
            'Username' => '@'.$profile['username'],
            'Credited role' => $profile['role'],
            'Film industries' => $industries,
            'Specialisations' => $profile['specialisations'] ?? null,
            'Working languages' => $profile['languages'] ?? null,
            'Base city' => $profile['city'] ?? null,
            'Travel preferences' => $profile['travel'] ?? null,
            'Availability' => $profile['availability'] ?? null,
        ];
        $credentials = array_filter([
            'Awards and festivals' => $profile['highlights'] ?? null,
            'Training' => $profile['training'] ?? null,
            'Self-reported memberships' => $profile['memberships'] ?? null,
        ], fn ($value) => filled($value));
        $platforms = ['instagram' => 'Instagram', 'youtube' => 'YouTube', 'linkedin' => 'LinkedIn', 'imdb' => 'IMDb', 'facebook' => 'Facebook', 'x' => 'X'];
        $links = array_filter([
            'Website' => $profile['social'] ?? null,
            'Showreel' => $profile['showreel'] ?? null,
        ]);
        foreach ($profile['socialLinks'] ?? [] as $platform => $url) {
            if (isset($platforms[$platform]) && filled($url)) {
                $links[$platforms[$platform]] = $url;
            }
        }
        $projects = $profile['portfolio'] ?? [];
        $slides = $profile['heroSlides'] ?? [];
        if (!$slides && !empty($profile['banner'])) {
            $slides = [$profile['banner']];
        }
    @endphp
    <a class="skip-link" href="#main-content">Skip to profile</a>
    <aside class="sidebar" aria-label="Profile sidebar">
        <a class="brand" href="{{ route('home') }}" aria-label="FILMUNITY home">
            <img src="{{ asset('brand/filmunity-logo-full.png') }}" alt="FILMUNITY" width="144">
        </a>
        <a class="sidebar-identity" href="#home">
            <img class="sidebar-avatar" src="{{ $profile['photo'] }}" alt="" width="108" height="108">
            <span>{{ $profile['name'] }}</span>
        </a>
        <button class="profile-menu-toggle" type="button" aria-expanded="false" aria-controls="profile-section-nav" hidden>
            <span aria-hidden="true">☰</span> Menu
        </button>
        <nav id="profile-section-nav" class="section-nav" aria-label="Profile sections">
            @php
            $navigation = [
                'home' => ['Home', 'M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z'],
                'about' => ['About', 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2 M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z'],
                'resume' => ['Resume', 'M6 3h8l4 4v14H6z M14 3v5h4 M9 12h6 M9 16h6'],
                'portfolio' => ['Portfolio', 'M3 8h18v12H3z M8 8V5h8v3 M3 13h18 M10 13v2h4v-2'],
                'services' => ['Services', 'M4 6h16 M4 12h16 M4 18h16 M8 4v4 M15 10v4 M10 16v4'],
                'contact' => ['Contact', 'M3 5h18v14H3z M3 6l9 7 9-7'],
            ];
            @endphp
            @foreach ($navigation as $section => [$label, $iconPath])
                <a href="#{{ $section }}" @if ($loop->first) aria-current="location" @endif>
                    <span class="section-nav-icon" aria-hidden="true">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                            <path d="{{ $iconPath }}" />
                        </svg>
                    </span>
                    <span>{{ $label }}</span>
                </a>
            @endforeach
        </nav>
        <ul class="sidebar-social" aria-label="Social profiles">
            @foreach ($platforms as $platform => $label)
                @php $url = $profile['socialLinks'][$platform] ?? null; @endphp
                <li>
                    <a href="{{ filled($url) ? $url : '#' }}"
                        @if (filled($url))
                            rel="ugc nofollow" aria-label="{{ $label }}"
                        @else
                            aria-label="{{ $label }} (link not added)" aria-disabled="true" tabindex="-1"
                        @endif
                    >
                        <img src="{{ asset('brand/social/'.$platform.'.svg') }}" width="16" height="16" alt="">
                    </a>
                </li>
            @endforeach
        </ul>
    </aside>

    <main id="main-content" tabindex="-1">
        <section id="home" class="hero {{ $slides ? 'has-banner' : '' }} {{ count($slides) > 1 ? 'has-carousel' : '' }}" aria-labelledby="member-name">
            @if ($slides)
                <div class="hero-media">
                    @foreach ($slides as $slide)
                        <img class="hero-image" src="{{ $slide }}" alt="" @if (!$loop->first) hidden @endif>
                    @endforeach
                </div>
            @endif
            <div class="hero-content">
                <p class="handle">{{ '@'.$profile['username'] }}</p>
                <h1 id="member-name">{{ $profile['name'] }}</h1>
                @if (!empty($profile['headline']))
                    <p class="hero-headline">{{ $profile['headline'] }}</p>
                @endif
                <p class="hero-meta">{{ $profile['role'] }} · {{ $industries }}</p>
                @if (!empty($profile['openToEnquiries']))
                    <p class="enquiries">Open to project enquiries</p>
                @endif
            </div>
            @if (count($slides) > 1)
                <div class="carousel-controls" aria-label="Home image slider controls" hidden>
                    <button type="button" data-slide-previous aria-label="Previous image"><span aria-hidden="true">←</span></button>
                    <span data-slide-count aria-live="off">1 / {{ count($slides) }}</span>
                    <button type="button" data-slide-next aria-label="Next image"><span aria-hidden="true">→</span></button>
                    <button type="button" data-slide-play>Pause</button>
                </div>
            @endif
        </section>

        <section id="about" class="profile-section" aria-labelledby="about-heading">
            <div class="section-content">
                <h2 id="about-heading">About</h2>
                <p class="bio">{{ $profile['bio'] }}</p>
                <div class="about-grid">
                    <img class="about-photo" src="{{ $profile['photo'] }}" alt="Portrait of {{ $profile['name'] }}" width="360" height="360">
                    <div>
                        <h3>{{ ($profile['headline'] ?? '') ?: $profile['role'] }}</h3>
                        <dl class="facts">
                            @foreach ($details as $label => $value)
                                @if (filled($value))
                                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                                @endif
                            @endforeach
                        </dl>
                    </div>
                </div>
            </div>
        </section>

        <section id="resume" class="profile-section tinted" aria-labelledby="resume-heading">
            <div class="section-content">
                <h2 id="resume-heading">Resume</h2>
                <div class="resume-entry">
                    <h3>{{ $profile['role'] }}</h3>
                    <p class="muted">{{ $industries }}</p>
                </div>
                @if ($credentials)
                    <dl class="credentials">
                        @foreach ($credentials as $label => $value)
                            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                @endif
            </div>
        </section>

        <section id="portfolio" class="profile-section" aria-labelledby="portfolio-heading">
            <div class="section-content">
                <h2 id="portfolio-heading">Portfolio</h2>
                @if ($projects)
                    <div class="projects">
                        @foreach ($projects as $project)
                            <article class="project">
                                @if (!empty($project['featured']))
                                    <p class="featured">Featured project</p>
                                @endif
                                <h3>{{ $project['title'] }}</h3>
                                <p class="muted">{{ implode(' · ', array_filter([$project['role'], $project['type'] ?? '', $project['year'] ?? ''])) }}</p>
                                @if (!empty($project['contribution']))
                                    <p class="preserve-lines">{{ $project['contribution'] }}</p>
                                @endif
                                @if (!empty($project['url']))
                                    <a href="{{ $project['url'] }}" rel="ugc nofollow">View project <span aria-hidden="true">↗</span></a>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @else
                    <p class="muted">No projects published yet.</p>
                @endif
            </div>
        </section>

        <section id="services" class="profile-section tinted" aria-labelledby="services-heading">
            <div class="section-content">
                <h2 id="services-heading">Services</h2>
                <div class="projects">
                    <article class="project">
                        <h3>{{ $profile['role'] }}</h3>
                        <p class="muted">{{ $industries }}</p>
                        @if (!empty($profile['specialisations']))
                            <p class="preserve-lines">{{ $profile['specialisations'] }}</p>
                        @endif
                        @if (!empty($profile['availability']))
                            <p class="muted">Availability · {{ $profile['availability'] }}</p>
                        @endif
                    </article>
                </div>
            </div>
        </section>

        <section id="contact" class="profile-section" aria-labelledby="contact-heading">
            <div class="section-content">
                <h2 id="contact-heading">Contact</h2>
                @if (!empty($profile['openToEnquiries']))
                    <p class="contact-note">Open to project enquiries.</p>
                @endif
                @if ($links)
                    <ul class="professional-links">
                        @foreach ($links as $label => $url)
                            <li><a href="{{ $url }}" rel="ugc nofollow">{{ $label }} <span aria-hidden="true">↗</span></a></li>
                        @endforeach
                    </ul>
                @else
                    <p class="muted">No public contact links added.</p>
                @endif
            </div>
        </section>
        <footer><a href="#home">Back to top <span aria-hidden="true">↑</span></a></footer>
    </main>
</body>
</html>
