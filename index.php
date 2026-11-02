<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ZVECA | Grand Diwali Launch - Next-Gen HRMS & CRM</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=Cinzel:wght@600;700;900&display=swap" rel="stylesheet">
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        cinzel: ['"Cinzel"', 'serif'],
                    },
                    colors: {
                        obsidian: '#030611',
                        midnight: '#070C1E',
                        brandCyan: '#00E5FF',
                        brandBlue: '#0072FF',
                        festiveGold: '#FFD700',
                        festiveAmber: '#FF8A00'
                    },
                    animation: {
                        'mandala-spin': 'spin 90s linear infinite',
                        'mandala-reverse': 'spinReverse 75s linear infinite',
                        'flame-flicker': 'flameWiggle 1.8s ease-in-out infinite alternate',
                        'beam-slide': 'beamSlide 2.6s linear infinite',
                        'pulse-glow': 'pulseGlow 2.5s ease-in-out infinite',
                    },
                    keyframes: {
                        spinReverse: {
                            '0%': { transform: 'rotate(360deg)' },
                            '100%': { transform: 'rotate(0deg)' }
                        },
                        flameWiggle: {
                            '0%': { transform: 'scale(1) skewX(-1.5deg)' },
                            '50%': { transform: 'scale(1.08, 0.95) skewX(2deg)' },
                            '100%': { transform: 'scale(0.96, 1.05) skewX(-1deg)' }
                        },
                        beamSlide: {
                            '0%': { transform: 'translateX(-150%) skewX(-20deg)' },
                            '100%': { transform: 'translateX(250%) skewX(-20deg)' }
                        },
                        pulseGlow: {
                            '0%, 100%': { filter: 'drop-shadow(0 0 20px rgba(0, 229, 255, 0.4))' },
                            '50%': { filter: 'drop-shadow(0 0 40px rgba(255, 215, 0, 0.7)) drop-shadow(0 0 70px rgba(0, 114, 255, 0.5))' }
                        }
                    }
                }
            }
        }
    </script>

    <style>
        html, body {
            height: 100vh;
            width: 100vw;
            overflow: hidden;
            background-color: #030611;
            color: #F8FAFC;
            font-family: 'Plus Jakarta Sans', sans-serif;
            touch-action: manipulation;
        }

        /* Ambient Luxury Glass Surface */
        .glass-card {
            background: radial-gradient(120% 120% at 50% 10%, rgba(16, 26, 56, 0.65) 0%, rgba(4, 8, 22, 0.88) 100%);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(0, 229, 255, 0.16);
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.8), inset 0 1px 0 rgba(255, 255, 255, 0.1);
        }

        /* Gold Foil Typography */
        .text-festive-shimmer {
            background: linear-gradient(135deg, #FFFFFF 0%, #FFF2A8 25%, #FFD700 50%, #FF9900 78%, #FF5500 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .text-brand-gradient {
            background: linear-gradient(135deg, #FFFFFF 0%, #70E5FF 40%, #0084FF 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Master Launch CTA */
        .epic-cta-btn {
            background: linear-gradient(135deg, #0088FF 0%, #0052D4 30%, #FF8A00 70%, #FFD700 100%);
            background-size: 250% 250%;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 0 40px rgba(0, 140, 255, 0.6), 0 0 80px rgba(255, 170, 0, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.4);
        }
        .epic-cta-btn:hover {
            transform: scale(1.03) translateY(-2px);
            box-shadow: 0 0 65px rgba(0, 229, 255, 0.9), 0 0 110px rgba(255, 215, 0, 0.7);
        }

        /* Screen blast vibration */
        .camera-blast-shake {
            animation: cameraBlast 0.7s cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
        }
        @keyframes cameraBlast {
            10%, 90% { transform: translate3d(-4px, 3px, 0) rotate(-0.5deg); }
            20%, 80% { transform: translate3d(5px, -4px, 0) rotate(0.7deg); }
            30%, 50%, 70% { transform: translate3d(-8px, 6px, 0) rotate(-1deg); }
            40%, 60% { transform: translate3d(8px, -6px, 0) rotate(1deg); }
        }

        /* Dynamic SVG Shockwave Ring */
        .shockwave-ring {
            position: fixed;
            border-radius: 50%;
            pointer-events: none;
            border: 2px solid #FFD700;
            box-shadow: 0 0 40px #00E5FF;
            transform: translate(-50%, -50%) scale(0.1);
            opacity: 1;
            transition: transform 0.85s cubic-bezier(0.1, 0.9, 0.2, 1), opacity 0.85s ease-out;
            z-index: 9991;
        }

        #fxCanvas {
            position: fixed;
            inset: 0;
            width: 100vw;
            height: 100vh;
            pointer-events: none;
            z-index: 9989;
        }
    </style>
</head>
<body id="viewportBody" class="flex flex-col justify-between h-screen w-screen overflow-hidden select-none relative bg-obsidian">

    <!-- Pyrotechnics Canvas -->
    <canvas id="fxCanvas"></canvas>

    <!-- Shockwave Expansion Target -->
    <div id="shockwave" class="shockwave-ring hidden"></div>

    <!-- ======================================================== -->
    <!-- BACKGROUND SACRED MANDALA & LIGHTING INFRASTRUCTURE      -->
    <!-- ======================================================== -->
    <div class="fixed inset-0 pointer-events-none -z-10 overflow-hidden">
        <!-- Giant Rotating Sacred Mandala Watermark -->
        <svg class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[900px] h-[900px] opacity-[0.06] animate-mandala-spin" viewBox="0 0 500 500" fill="none">
            <circle cx="250" cy="250" r="230" stroke="#00E5FF" stroke-width="1.5" stroke-dasharray="8 8"/>
            <circle cx="250" cy="250" r="180" stroke="#FFD700" stroke-width="1.5" stroke-dasharray="12 6"/>
            <circle cx="250" cy="250" r="130" stroke="#0072FF" stroke-width="2"/>
            <g stroke="#FFD700" stroke-width="1">
                <path d="M250 20 L270 200 L480 250 L270 300 L250 480 L230 300 L20 250 L230 200 Z" />
                <path d="M87 87 L220 220 L413 87 L280 250 L413 413 L250 280 L87 413 L220 250 Z" />
            </g>
        </svg>

        <!-- Ambient Volumetric Glow Orbs -->
        <div class="absolute -top-32 left-1/2 -translate-x-1/2 w-[650px] h-[360px] bg-gradient-to-b from-brandBlue/30 via-brandCyan/20 to-transparent rounded-full blur-[130px]"></div>
        <div class="absolute -bottom-24 left-1/2 -translate-x-1/2 w-[720px] h-[320px] bg-gradient-to-t from-festiveAmber/25 via-festiveGold/15 to-transparent rounded-full blur-[140px]"></div>
    </div>

    <!-- ======================================================== -->
    <!-- 1. COMPACT TOP HEADER                                    -->
    <!-- ======================================================== -->
    <header class="w-full px-4 sm:px-8 py-3.5 flex items-center justify-between border-b border-white/10 glass-card z-30">
        <!-- Brand Signature (Vector Ribbon Match) -->
        <a href="https://zveca.com/" class="flex items-center gap-3 group">
            <div class="relative w-9 h-9 rounded-xl bg-gradient-to-br from-brandCyan via-brandBlue to-indigo-700 p-[1.5px] shadow-lg shadow-brandCyan/30">
                <div class="w-full h-full bg-[#05091B] rounded-[10px] flex items-center justify-center">
                    <span class="text-brandCyan font-black text-base tracking-tighter group-hover:scale-110 transition-transform">Z↗</span>
                </div>
            </div>
            <div class="flex flex-col">
                <div class="flex items-center gap-2">
                    <span class="text-xl font-black tracking-tight text-white font-sans">ZVECA</span>
                    <span class="text-[9px] px-2 py-0.5 rounded-full bg-brandCyan/20 text-brandCyan font-bold tracking-wider border border-brandCyan/40 uppercase">AI Cloud</span>
                </div>
                <span class="text-[9px] text-gray-400 font-medium tracking-wide">Next-Gen HRMS & CRM Platform</span>
            </div>
        </a>

        <!-- Shubh Deepawali Edition Pill -->
        <div class="hidden md:flex items-center gap-2 px-4 py-1.5 rounded-full bg-gradient-to-r from-festiveAmber/20 via-festiveGold/10 to-brandCyan/15 border border-festiveGold/40 text-xs font-semibold text-festiveGold shadow-md">
            <span class="w-2 h-2 rounded-full bg-festiveGold animate-ping"></span>
            <span class="font-cinzel tracking-wider">Shubh Aarambh 2026</span>
            <span class="text-gray-400">•</span>
            <span class="text-gray-200">Diwali Enterprise Release</span>
        </div>

        <!-- Audio Toggle & Official Link -->
        <div class="flex items-center gap-3">
            <button id="soundBtn" onclick="toggleSoundFX()" class="flex items-center gap-2 px-3.5 py-1.5 rounded-xl bg-white/5 border border-white/15 hover:border-festiveGold/60 text-xs font-medium text-gray-200 transition-all hover:bg-white/10">
                <span id="soundEmoji" class="text-sm">🔊</span>
                <span id="soundStatus" class="text-[11px] uppercase tracking-wider text-festiveGold font-bold">Sound: ON</span>
            </button>
            <a href="https://zveca.com/" class="hidden sm:flex items-center gap-1.5 text-xs font-semibold text-gray-300 hover:text-brandCyan transition-colors">
                <span>zveca.com</span>
                <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
            </a>
        </div>
    </header>

    <!-- ======================================================== -->
    <!-- 2. CENTRAL HERO ZONE (100vh Single Screen Viewport)     -->
    <!-- ======================================================== -->
    <main class="flex-1 flex flex-col items-center justify-center text-center px-4 max-w-5xl mx-auto w-full relative z-20 my-auto">
        
        <!-- Flanking Traditional Diyas (Desktop) -->
        <div class="hidden lg:flex items-center justify-between w-full absolute inset-x-0 pointer-events-none px-6">
            <!-- Left Royal Diya -->
            <div class="flex flex-col items-center">
                <svg class="w-14 h-14 filter drop-shadow-[0_0_16px_rgba(255,170,0,0.85)]" viewBox="0 0 64 64" fill="none">
                    <path class="origin-bottom animate-flame-flicker" d="M32 3C32 3 20 19 20 28C20 34.6 25.4 40 32 40C38.6 40 44 34.6 44 28C44 19 32 3 32 3Z" fill="url(#flameGradLeft)"/>
                    <path d="M10 38C10 50 20 56 32 56C44 56 54 50 54 38H10Z" fill="url(#diyaBodyLeft)"/>
                    <ellipse cx="32" cy="38" rx="22" ry="4.5" fill="#B45309"/>
                    <defs>
                        <radialGradient id="flameGradLeft" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(32 26) scale(14)">
                            <stop stop-color="#FFFFFF"/>
                            <stop offset="0.3" stop-color="#FFEB3B"/>
                            <stop offset="0.7" stop-color="#FF9800"/>
                            <stop offset="1" stop-color="#E65100"/>
                        </radialGradient>
                        <linearGradient id="diyaBodyLeft" x1="10" y1="38" x2="54" y2="56" gradientUnits="userSpaceOnUse">
                            <stop stop-color="#F59E0B"/>
                            <stop offset="1" stop-color="#78350F"/>
                        </linearGradient>
                    </defs>
                </svg>
                <span class="text-[10px] font-cinzel font-bold text-festiveGold mt-1.5 uppercase tracking-widest">Lakshmi Kripa</span>
            </div>

            <!-- Right Royal Diya -->
            <div class="flex flex-col items-center">
                <svg class="w-14 h-14 filter drop-shadow-[0_0_16px_rgba(255,170,0,0.85)]" viewBox="0 0 64 64" fill="none">
                    <path class="origin-bottom animate-flame-flicker" style="animation-delay: 0.9s;" d="M32 3C32 3 20 19 20 28C20 34.6 25.4 40 32 40C38.6 40 44 34.6 44 28C44 19 32 3 32 3Z" fill="url(#flameGradLeft)"/>
                    <path d="M10 38C10 50 20 56 32 56C44 56 54 50 54 38H10Z" fill="url(#diyaBodyLeft)"/>
                    <ellipse cx="32" cy="38" rx="22" ry="4.5" fill="#B45309"/>
                </svg>
                <span class="text-[10px] font-cinzel font-bold text-brandCyan mt-1.5 uppercase tracking-widest">Shubh Labh</span>
            </div>
        </div>

        <!-- ======================================================== -->
        <!-- HIGH-ACCURACY 3D RIBBON LOGO EMBLEM (Matches Image)     -->
        <!-- ======================================================== -->
        <div class="mb-3 transform hover:scale-105 transition-transform duration-300">
            <div class="relative flex items-center justify-center">
                <!-- Dual Glowing Backing Aura -->
                <div class="absolute inset-0 bg-gradient-to-r from-brandCyan via-brandBlue to-festiveGold blur-3xl opacity-50 rounded-full animate-pulse-glow"></div>

                <!-- Glass Platform Showcase -->
                <div class="relative py-3.5 px-8 sm:px-12 rounded-3xl glass-card border border-brandCyan/40 shadow-2xl flex items-center justify-center">
                    <!-- Vector Recreation of ZVECA 3D Ribbon + Soaring Arrow -->
                    <svg class="w-64 sm:w-80 md:w-96 h-14 sm:h-18 md:h-20" viewBox="0 0 460 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="ribbonCyanPrimary" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#00F0FF"/>
                                <stop offset="45%" stop-color="#0080FF"/>
                                <stop offset="100%" stop-color="#003580"/>
                            </linearGradient>
                            <linearGradient id="ribbonDarkFold" x1="100%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#002D6B"/>
                                <stop offset="60%" stop-color="#001438"/>
                                <stop offset="100%" stop-color="#004C99"/>
                            </linearGradient>
                            <linearGradient id="arrowGlowGrad" x1="0%" y1="100%" x2="100%" y2="0%">
                                <stop offset="0%" stop-color="#0072FF"/>
                                <stop offset="60%" stop-color="#00D2FF"/>
                                <stop offset="100%" stop-color="#FFFFFF"/>
                            </linearGradient>
                            <filter id="vectorDropShadow" x="-10%" y="-10%" width="130%" height="130%">
                                <feDropShadow dx="0" dy="6" stdDeviation="6" flood-color="#000000" flood-opacity="0.7"/>
                            </filter>
                        </defs>

                        <!-- 'Z' Outer Ribbon Structure -->
                        <path d="M15 18 H95 L45 82 H125 V94 H12 L62 30 H15 V18 Z" fill="url(#ribbonCyanPrimary)" filter="url(#vectorDropShadow)"/>
                        <!-- 'Z' Inner Fold Shadow -->
                        <path d="M45 82 L72 48 L62 30 L28 72 Z" fill="url(#ribbonDarkFold)"/>

                        <!-- 'V' & 'E' Continuous Curving Silk Ribbon Loop -->
                        <path d="M135 25 C150 25 165 48 180 72 C192 48 205 25 220 25 C238 25 235 60 235 72 C235 88 252 90 270 70 L278 78 C252 108 220 102 218 72 C217 50 202 40 190 40 C176 40 165 62 155 85 H132 L162 25 Z" fill="url(#ribbonCyanPrimary)" filter="url(#vectorDropShadow)"/>
                        <path d="M180 72 C172 58 165 45 155 45 C145 45 140 55 138 65 L132 85 H148 C158 68 168 55 178 52 Z" fill="url(#ribbonDarkFold)"/>

                        <!-- 'C' Sweeping Cylinder Arc -->
                        <path d="M335 24 C298 24 274 44 274 70 C274 94 300 94 320 86 L325 94 C298 104 256 102 256 70 C256 36 290 16 335 16 C352 16 364 22 372 30 L360 38 C354 30 346 24 335 24 Z" fill="url(#ribbonCyanPrimary)" filter="url(#vectorDropShadow)"/>

                        <!-- 'A' Base Structure -->
                        <path d="M370 94 L404 36 L424 72 C406 78 384 82 370 94 Z" fill="url(#ribbonCyanPrimary)"/>

                        <!-- The Signature Soaring Arrow Bursting from 'A' -->
                        <path d="M352 86 C376 78 412 68 434 40 L425 35 L456 22 L452 56 L442 46 C416 78 376 90 352 86 Z" fill="url(#arrowGlowGrad)" filter="url(#vectorDropShadow)"/>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Headline & Typography -->
        <h1 class="text-3xl sm:text-5xl md:text-6xl font-black tracking-tight text-white leading-tight mb-2 font-sans">
            Ignite Exponential Enterprise Scale With <br class="hidden sm:inline">
            <span class="text-festive-shimmer">ZVECA Unified Cloud</span>
        </h1>

        <!-- Subtitle -->
        <p class="text-xs sm:text-base text-gray-300 max-w-2xl mx-auto font-normal leading-relaxed mb-3">
            One powerhouse ecosystem unifying <strong class="text-brandCyan font-semibold">1-Click Payroll</strong>, <strong class="text-festiveGold font-semibold">Predictive Sales CRM</strong>, and <strong class="text-white font-semibold">Autonomous HR Workflows</strong>.
        </p>

        <!-- Exclusive Festive Offer Pill -->
        <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-festiveGold/10 border border-festiveGold/40 text-festiveGold text-[11px] sm:text-xs font-semibold mb-5 shadow-sm">
            <span class="text-sm">🪔</span>
            <span>Diwali Special: Claim 3 Months Complimentary Cloud Access on Launch Day</span>
        </div>

        <!-- ======================================================== -->
        <!-- MASTER LAUNCH BUTTON (EXPLOSIVE DHAMAKA TRIGGER)         -->
        <!-- ======================================================== -->
        <div class="relative my-1">
            <!-- Pulsing Ambient Underglow -->
            <div class="absolute -inset-2 bg-gradient-to-r from-festiveGold via-festiveAmber to-brandCyan rounded-2xl blur-xl opacity-75 animate-pulse"></div>

            <button
                id="masterDhamakaBtn"
                onclick="executeDhamakaLaunch(event)"
                class="relative epic-cta-btn text-white font-black text-base sm:text-xl md:text-2xl px-8 sm:px-14 py-4 sm:py-5 rounded-2xl flex items-center justify-center gap-3 border-2 border-festiveGold/80 cursor-pointer active:scale-95 transition-all overflow-hidden group shadow-2xl"
            >
                <!-- Shimmer beam line -->
                <div class="absolute top-0 left-0 w-1/3 h-full bg-white/30 -skew-x-12 animate-beam-slide pointer-events-none"></div>

                <span class="text-2xl animate-bounce">🪔</span>
                <span class="tracking-wider uppercase drop-shadow-md text-white font-sans">
                    Launch ZVECA Ecosystem (धमाका शुरुआत)
                </span>
                <span class="text-2xl">💥</span>
            </button>
        </div>

        <!-- Interactive Hint -->
        <p class="text-[11px] sm:text-xs text-brandCyan/90 font-medium mt-3 flex items-center justify-center gap-1.5">
            <span>⚡ Detonates celebratory fireworks & auto-redirects to</span>
            <a href="https://zveca.com/" class="text-festiveGold font-bold underline hover:text-white transition-colors">https://zveca.com/</a>
        </p>

    </main>

    <!-- ======================================================== -->
    <!-- 3. COMPACT FOOTER DOCK (100vh Single Screen Viewport)     -->
    <!-- ======================================================== -->
    <footer class="w-full px-4 sm:px-8 py-3 glass-card border-t border-white/10 z-30">
        <div class="max-w-6xl mx-auto flex flex-wrap items-center justify-between gap-3 text-xs">
            
            <!-- Core Capabilities Summary -->
            <div class="flex flex-wrap items-center gap-4 sm:gap-8 text-gray-300 mx-auto md:mx-0">
                <div class="flex items-center gap-1.5 font-semibold hover:text-festiveGold transition-colors">
                    <span class="text-festiveGold">⚡</span>
                    <span>Automated Payroll & Tax</span>
                </div>
                <div class="flex items-center gap-1.5 font-semibold hover:text-brandCyan transition-colors">
                    <span class="text-brandCyan">🎯</span>
                    <span>High-Velocity Sales CRM</span>
                </div>
                <div class="flex items-center gap-1.5 font-semibold hover:text-emerald-400 transition-colors">
                    <span class="text-emerald-400">📍</span>
                    <span>Geo-Fence Biometrics</span>
                </div>
                <div class="flex items-center gap-1.5 font-semibold hover:text-indigo-400 transition-colors">
                    <span class="text-indigo-400">🤖</span>
                    <span>Autonomous AI Co-Pilot</span>
                </div>
            </div>

            <!-- Enterprise Trust Certifications -->
            <div class="hidden lg:flex items-center gap-2.5 text-[11px] text-gray-400 font-mono">
                <span class="px-2.5 py-0.5 rounded bg-white/5 border border-white/10">SOC-2 Type II</span>
                <span class="px-2.5 py-0.5 rounded bg-white/5 border border-white/10">ISO 27001</span>
                <span class="px-2.5 py-0.5 rounded bg-white/5 border border-white/10">99.99% Uptime</span>
            </div>
        </div>
    </footer>

    <!-- ======================================================== -->
    <!-- 4. CELEBRATION CLIMAX MODAL & 3-SEC REDIRECTION PORTAL    -->
    <!-- ======================================================== -->
    <div id="celebrationModal" class="fixed inset-0 bg-black/92 backdrop-blur-2xl z-[9999] hidden flex-col items-center justify-center p-4">
        <div class="relative max-w-md w-full glass-card p-8 rounded-3xl border-2 border-festiveGold/80 text-center shadow-[0_0_120px_rgba(0,229,255,0.4)]">
            
            <!-- Festive Emoticons -->
            <div class="text-4xl mb-2 animate-bounce">🎇🪔💥</div>

            <h3 class="text-2xl sm:text-3xl font-black text-festive-shimmer font-cinzel uppercase tracking-wider mb-1">
                BOOM! SHUBH AARAMBH!
            </h3>
            <p class="text-xs sm:text-sm text-brandCyan font-semibold mb-6">
                Welcome to the Future of Enterprise HRMS & CRM
            </p>

            <!-- ZVECA Brand Crest Inside Modal -->
            <div class="py-3 px-6 bg-black/60 rounded-2xl border border-brandCyan/40 mb-6 flex items-center justify-center gap-2 shadow-inner">
                <span class="text-3xl font-black text-brandCyan font-sans">ZVECA</span>
                <span class="text-2xl font-black text-festiveGold">↗</span>
            </div>

            <!-- Animated Radial Countdown Ring -->
            <div class="relative w-24 h-24 mx-auto mb-5 flex items-center justify-center">
                <svg class="w-full h-full transform -rotate-90">
                    <circle cx="48" cy="48" r="40" stroke="rgba(255,255,255,0.15)" stroke-width="5" fill="none" />
                    <circle id="countdownRing" cx="48" cy="48" r="40" stroke="#FFD700" stroke-width="5" stroke-linecap="round" fill="none"
                        stroke-dasharray="251.3" stroke-dashoffset="0" class="transition-all duration-1000 ease-linear" />
                </svg>
                <span id="countdownNumber" class="absolute text-2xl font-black text-white font-mono">3</span>
            </div>

            <div class="text-[11px] text-gray-400 font-mono mb-4">
                Teleporting directly to <strong class="text-festiveGold">https://zveca.com/</strong>
            </div>

            <button onclick="window.location.href='https://zveca.com/'" class="text-xs text-brandCyan hover:text-white underline font-semibold uppercase tracking-wider transition-colors">
                Skip Countdown & Enter Now ➔
            </button>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- 5. SYNTHESIZED WEB AUDIO & PYROTECHNIC PARTICLES SCRIPT  -->
    <!-- ======================================================== -->
    <script>
        lucide.createIcons();

        // -------------------------------------------------------------
        // WEB AUDIO SYNTHESIS: Multi-Stage Explosive Patakha Engine
        // -------------------------------------------------------------
        let audioCtx = null;
        let isAudioActive = true;

        function initAudio() {
            if (!audioCtx) {
                const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (AudioContextClass) audioCtx = new AudioContextClass();
            }
            if (audioCtx && audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
            return audioCtx;
        }

        function toggleSoundFX() {
            isAudioActive = !isAudioActive;
            const statusEl = document.getElementById('soundStatus');
            const emojiEl = document.getElementById('soundEmoji');
            if (isAudioActive) {
                statusEl.innerText = "Sound: ON";
                emojiEl.innerText = "🔊";
                initAudio();
                playGrandDhamakaAudio();
            } else {
                statusEl.innerText = "Sound: OFF";
                emojiEl.innerText = "🔇";
            }
        }

        function playGrandDhamakaAudio() {
            if (!isAudioActive) return;
            try {
                const ctx = initAudio();
                if (!ctx) return;
                const now = ctx.currentTime;

                // Stage 1: Ascending High-Velocity Rocket Whistle
                const whistleOsc = ctx.createOscillator();
                const whistleGain = ctx.createGain();
                whistleOsc.type = 'sine';
                whistleOsc.frequency.setValueAtTime(320, now);
                whistleOsc.frequency.exponentialRampToValueAtTime(1700, now + 0.35);

                whistleGain.gain.setValueAtTime(0.01, now);
                whistleGain.gain.linearRampToValueAtTime(0.38, now + 0.22);
                whistleGain.gain.exponentialRampToValueAtTime(0.001, now + 0.36);

                whistleOsc.connect(whistleGain);
                whistleGain.connect(ctx.destination);
                whistleOsc.start(now);
                whistleOsc.stop(now + 0.36);

                // Stage 2: Heavy Sub-Bass Subwoofer Explosion ("BUM")
                const boomTime = now + 0.32;
                const subOsc = ctx.createOscillator();
                const subGain = ctx.createGain();
                subOsc.type = 'triangle';
                subOsc.frequency.setValueAtTime(160, boomTime);
                subOsc.frequency.exponentialRampToValueAtTime(22, boomTime + 0.95);

                subGain.gain.setValueAtTime(1.0, boomTime);
                subGain.gain.exponentialRampToValueAtTime(0.01, boomTime + 0.95);

                subOsc.connect(subGain);
                subGain.connect(ctx.destination);
                subOsc.start(boomTime);
                subOsc.stop(boomTime + 0.95);

                // Stage 3: Kinetic Noise Shockwave Blast
                const bufferSize = ctx.sampleRate * 0.8;
                const buffer = ctx.createBuffer(1, bufferSize, ctx.sampleRate);
                const data = buffer.getChannelData(0);
                for (let i = 0; i < bufferSize; i++) {
                    data[i] = (Math.random() * 2 - 1) * Math.exp(-i / (ctx.sampleRate * 0.16));
                }

                const noise = ctx.createBufferSource();
                noise.buffer = buffer;

                const filter = ctx.createBiquadFilter();
                filter.type = "lowpass";
                filter.frequency.setValueAtTime(1500, boomTime);
                filter.frequency.exponentialRampToValueAtTime(55, boomTime + 0.7);

                const noiseGain = ctx.createGain();
                noiseGain.gain.setValueAtTime(1.0, boomTime);
                noiseGain.gain.exponentialRampToValueAtTime(0.01, boomTime + 0.8);

                noise.connect(filter);
                filter.connect(noiseGain);
                noiseGain.connect(ctx.destination);
                noise.start(boomTime);

                // Stage 4: Rapid Patakha Spark Crackles
                for (let i = 0; i < 9; i++) {
                    const popTime = boomTime + 0.18 + Math.random() * 0.65;
                    const popOsc = ctx.createOscillator();
                    const popGain = ctx.createGain();
                    popOsc.type = 'square';
                    popOsc.frequency.setValueAtTime(700 + Math.random() * 1100, popTime);
                    popGain.gain.setValueAtTime(0.18, popTime);
                    popGain.gain.exponentialRampToValueAtTime(0.001, popTime + 0.05);

                    popOsc.connect(popGain);
                    popGain.connect(ctx.destination);
                    popOsc.start(popTime);
                    popOsc.stop(popTime + 0.05);
                }
            } catch (err) {
                console.warn("Audio Context error:", err);
            }
        }

        // -------------------------------------------------------------
        // CANVAS FIRECRACKER ENGINE: Multi-Stage Rockets & Spark Physics
        // -------------------------------------------------------------
        const canvas = document.getElementById('fxCanvas');
        const cCtx = canvas.getContext('2d');
        let rockets = [];
        let particles = [];
        let isLoopRunning = false;

        function resizeCanvas() {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        }
        window.addEventListener('resize', resizeCanvas);
        resizeCanvas();

        const COLOR_SETS = [
            ['#00E5FF', '#0072FF', '#FFFFFF', '#70E5FF'], // Electric ZVECA Cyan & Cobalt
            ['#FFD700', '#FF9900', '#FF3D00', '#FFFFFF'], // Diwali Golden Flame
            ['#00E5FF', '#FFD700', '#FF3D00', '#FFFFFF']  // Grand Celebration Combination
        ];

        class Rocket {
            constructor(sx, sy, tx, ty) {
                this.x = sx;
                this.y = sy;
                this.tx = tx;
                this.ty = ty;
                const dist = Math.hypot(tx - sx, ty - sy);
                this.speed = Math.max(18, dist / 18);
                const angle = Math.atan2(ty - sy, tx - sx);
                this.vx = Math.cos(angle) * this.speed;
                this.vy = Math.sin(angle) * this.speed;
                this.trail = [];
            }
            update() {
                this.trail.push({ x: this.x, y: this.y });
                if (this.trail.length > 8) this.trail.shift();
                this.x += this.vx;
                this.y += this.vy;
                return Math.hypot(this.tx - this.x, this.ty - this.y) >= 22 && this.y > this.ty;
            }
            draw(ctx) {
                for (let i = 0; i < this.trail.length; i++) {
                    const p = this.trail[i];
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, 2, 0, Math.PI * 2);
                    ctx.fillStyle = `rgba(0, 229, 255, ${i / this.trail.length})`;
                    ctx.fill();
                }
                ctx.beginPath();
                ctx.arc(this.x, this.y, 4, 0, Math.PI * 2);
                ctx.fillStyle = '#FFF';
                ctx.shadowColor = '#00E5FF';
                ctx.shadowBlur = 12;
                ctx.fill();
            }
        }

        class Spark {
            constructor(x, y, color) {
                this.x = x;
                this.y = y;
                this.color = color;
                const angle = Math.random() * Math.PI * 2;
                const speed = Math.random() * 14 + 2;
                this.vx = Math.cos(angle) * speed;
                this.vy = Math.sin(angle) * speed;
                this.gravity = 0.14;
                this.drag = 0.94;
                this.alpha = 1;
                this.decay = Math.random() * 0.016 + 0.012;
                this.size = Math.random() * 3 + 1.5;
            }
            update() {
                this.vx *= this.drag;
                this.vy = this.vy * this.drag + this.gravity;
                this.x += this.vx;
                this.y += this.vy;
                this.alpha -= this.decay;
                return this.alpha > 0;
            }
            draw(ctx) {
                ctx.save();
                ctx.globalAlpha = Math.max(0, this.alpha);
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fillStyle = this.color;
                ctx.shadowColor = this.color;
                ctx.shadowBlur = 8;
                ctx.fill();
                ctx.restore();
            }
        }

        function createBlast(x, y) {
            const palette = COLOR_SETS[Math.floor(Math.random() * COLOR_SETS.length)];
            for (let i = 0; i < 140; i++) {
                const c = palette[Math.floor(Math.random() * palette.length)];
                particles.push(new Spark(x, y, c));
            }

            // Screen Blast Vibration
            const body = document.getElementById('viewportBody');
            body.classList.remove('camera-blast-shake');
            void body.offsetWidth;
            body.classList.add('camera-blast-shake');
            setTimeout(() => body.classList.remove('camera-blast-shake'), 700);

            // Kinetic Shockwave Circle Expansion
            triggerShockwave(x, y);
        }

        function triggerShockwave(x, y) {
            const ring = document.getElementById('shockwave');
            ring.style.left = `${x}px`;
            ring.style.top = `${y}px`;
            ring.style.transform = 'translate(-50%, -50%) scale(0.1)';
            ring.style.opacity = '1';
            ring.classList.remove('hidden');

            requestAnimationFrame(() => {
                ring.style.transform = 'translate(-50%, -50%) scale(14)';
                ring.style.opacity = '0';
            });
            setTimeout(() => ring.classList.add('hidden'), 850);
        }

        function spawnRocket(sx, sy, tx, ty) {
            rockets.push(new Rocket(sx, sy, tx, ty));
            if (!isLoopRunning) {
                isLoopRunning = true;
                requestAnimationFrame(animationLoop);
            }
        }

        function animationLoop() {
            cCtx.clearRect(0, 0, canvas.width, canvas.height);

            // Rockets
            for (let i = rockets.length - 1; i >= 0; i--) {
                const r = rockets[i];
                if (!r.update()) {
                    createBlast(r.tx, r.ty);
                    rockets.splice(i, 1);
                } else {
                    r.draw(cCtx);
                }
            }

            // Sparks
            for (let i = particles.length - 1; i >= 0; i--) {
                const s = particles[i];
                if (!s.update()) {
                    particles.splice(i, 1);
                } else {
                    s.draw(cCtx);
                }
            }

            if (rockets.length > 0 || particles.length > 0) {
                requestAnimationFrame(animationLoop);
            } else {
                isLoopRunning = false;
            }
        }

        // -------------------------------------------------------------
        // LAUNCH CLIMAX EXECUTION & 3-SECOND DIRECT REDIRECT
        // -------------------------------------------------------------
        let launchTriggered = false;

        function executeDhamakaLaunch(event) {
            if (launchTriggered) return;
            launchTriggered = true;

            const clickX = event && event.clientX ? event.clientX : window.innerWidth / 2;
            const clickY = event && event.clientY ? event.clientY : window.innerHeight / 2;

            // Trigger Patakha Audio Blast
            playGrandDhamakaAudio();

            // Hardware Haptic Feedback
            if (navigator.vibrate) {
                navigator.vibrate([120, 60, 220, 80, 500]);
            }

            // Detonate Multi-Rocket Pyrotechnic Trajectory
            const bottom = window.innerHeight;
            spawnRocket(window.innerWidth * 0.15, bottom, clickX - 120, clickY - 80);
            spawnRocket(window.innerWidth * 0.85, bottom, clickX + 120, clickY - 80);
            spawnRocket(window.innerWidth * 0.5, bottom, clickX, clickY - 150);

            setTimeout(() => {
                playGrandDhamakaAudio();
                spawnRocket(window.innerWidth * 0.28, bottom, window.innerWidth * 0.35, window.innerHeight * 0.25);
                spawnRocket(window.innerWidth * 0.72, bottom, window.innerWidth * 0.65, window.innerHeight * 0.25);
            }, 320);

            // Open Celebration Climax Modal
            setTimeout(() => {
                const modal = document.getElementById('celebrationModal');
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                startCountdownRing();
            }, 500);
        }

        function startCountdownRing() {
            let count = 3;
            const countEl = document.getElementById('countdownNumber');
            const ringEl = document.getElementById('countdownRing');
            const circumference = 2 * Math.PI * 40; // ~251.3

            const interval = setInterval(() => {
                count--;
                if (count > 0) {
                    countEl.innerText = count;
                    const offset = circumference * (1 - count / 3);
                    ringEl.style.strokeDashoffset = offset;
                    playGrandDhamakaAudio();
                } else {
                    clearInterval(interval);
                    countEl.innerText = "🚀";
                    ringEl.style.strokeDashoffset = circumference;
                    // Auto-teleport to ZVECA official domain
                    window.location.href = "https://zveca.com/";
                }
            }, 1000);
        }
    </script>
</body>
</html>