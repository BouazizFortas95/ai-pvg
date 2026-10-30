import { jsx as _jsx, jsxs as _jsxs } from "react/jsx-runtime";
import { useCurrentFrame, interpolate, AbsoluteFill, Sequence, Audio, useVideoConfig, } from 'remotion';
const TRANSITION_FRAMES = 18;
const THEMES = {
    breaking: { bg: '#05070a', accent: '#ff4d6d', glow: '#ff6b81', panel: 'rgba(255,255,255,0.08)', textDim: 'rgba(255,255,255,0.85)' },
    minimal: { bg: '#ffffff', accent: '#0ea5e9', glow: '#38bdf8', panel: 'rgba(0,0,0,0.05)', textDim: 'rgba(0,0,0,0.7)' },
    cyber: { bg: '#0a0c12', accent: '#00d4ff', glow: '#00f0ff', panel: 'rgba(255,255,255,0.08)', textDim: 'rgba(255,255,255,0.9)' },
};
export const VideoScript = ({ scenes, title, language, targetDuration, template = 'cyber', audioUrl, captionTrack, visualAssets, }) => {
    const frame = useCurrentFrame();
    const { width, height, fps } = useVideoConfig();
    const isRTL = language === 'ar';
    const theme = THEMES[template] ?? THEMES.cyber;
    const fp = fps ?? 30;
    const sorted = [...scenes].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
    const sceneStarts = [];
    let cumulative = 0;
    sorted.forEach((s) => {
        sceneStarts.push(cumulative);
        cumulative += Math.max(1, Math.round((s.duration || 5) * fp));
    });
    const totalDurationFrames = cumulative;
    if (frame >= totalDurationFrames) {
        return null;
    }
    const activeIndex = sorted.findIndex((_, i) => {
        const start = sceneStarts[i];
        const end = start + Math.max(1, Math.round((sorted[i].duration || 5) * fp));
        return frame >= start && frame < end;
    });
    const progress = totalDurationFrames > 0 ? interpolate(frame, [0, totalDurationFrames], [0, 100]) : 0;
    return (_jsxs(AbsoluteFill, { style: {
            background: theme.bg,
            color: '#ffffff',
            fontFamily: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
            direction: isRTL ? 'rtl' : 'ltr',
            overflow: 'hidden',
        }, children: [_jsx(HeaderStrip, { title: title, category: sorted[0]?.category ?? 'Breaking', progress: progress, theme: theme, isRTL: isRTL }), audioUrl && _jsx(Audio, { src: audioUrl, volume: 1, startFrom: 0 }), sorted.map((scene, idx) => {
                const startFrame = sceneStarts[idx];
                const sceneDurSec = scene.duration || (targetDuration / Math.max(sorted.length, 1));
                const sceneDurFrames = Math.max(Math.round(sceneDurSec * fp), 45);
                return (_jsx(Sequence, { from: startFrame, durationInFrames: sceneDurFrames, name: `Scene ${idx + 1}: ${scene.type}`, children: _jsx(ActiveScene, { scene: scene, sceneIndex: idx, totalScenes: sorted.length, startFrame: startFrame, durationSec: sceneDurSec, fp: fp, theme: theme, isRTL: isRTL, visualAssets: visualAssets, captionTrack: captionTrack }) }, `scene-${scene.order ?? idx}`));
            }), _jsx(AbsoluteFill, { style: {
                    top: 'auto',
                    bottom: 40,
                    left: 40,
                    right: 40,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: isRTL ? 'flex-end' : 'flex-start',
                    pointerEvents: 'none',
                    zIndex: 30,
                }, children: _jsx(FooterStrip, { title: title, language: language, targetDuration: targetDuration, isRTL: isRTL, theme: theme }) })] }));
};
function HeaderStrip({ title, category, progress, theme, isRTL }) {
    return (_jsxs(AbsoluteFill, { style: {
            top: 0,
            left: 0,
            right: 0,
            height: 80,
            display: 'flex',
            flexDirection: 'column',
            pointerEvents: 'none',
            zIndex: 30,
        }, children: [_jsxs("div", { style: {
                    position: 'absolute',
                    top: 18,
                    left: isRTL ? 'auto' : 40,
                    right: isRTL ? 40 : 'auto',
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 10,
                    background: 'rgba(0,0,0,0.45)',
                    border: `1px solid ${theme.accent}33`,
                    borderRadius: 999,
                    padding: '8px 20px',
                    backdropFilter: 'blur(12px)',
                    fontSize: 12,
                    fontWeight: 900,
                    letterSpacing: 0.2,
                    textTransform: 'uppercase',
                    color: theme.accent,
                    boxShadow: `0 0 24px ${theme.accent}22`,
                }, children: [_jsx("span", { style: {
                            width: 7,
                            height: 7,
                            borderRadius: '50%',
                            background: theme.accent,
                            boxShadow: `0 0 14px ${theme.accent}`,
                            animation: 'pulse 2s ease-in-out infinite',
                        } }), "BREAKING TECH NEWS"] }), _jsx("div", { style: {
                    position: 'absolute',
                    top: 46,
                    left: isRTL ? 'auto' : 40,
                    right: isRTL ? 40 : 'auto',
                    fontWeight: 800,
                    fontSize: 14,
                    letterSpacing: 0.08,
                    textTransform: 'uppercase',
                    color: 'rgba(255,255,255,0.9)',
                    textShadow: '0 2px 10px rgba(0,0,0,0.4)',
                }, children: category }), _jsx("div", { style: {
                    position: 'absolute',
                    bottom: 10,
                    left: 40,
                    right: 40,
                    height: 5,
                    borderRadius: 3,
                    background: 'rgba(255,255,255,0.12)',
                    overflow: 'hidden',
                }, children: _jsx("div", { style: {
                        height: '100%',
                        width: `${Math.max(0, Math.min(100, progress))}%`,
                        background: `linear-gradient(90deg, ${theme.accent}, ${theme.glow})`,
                        boxShadow: `0 0 20px ${theme.accent}66`,
                        transition: 'width 0.1s linear',
                    } }) })] }));
}
function ActiveScene({ scene, sceneIndex, totalScenes, startFrame, durationSec, fp, theme, isRTL, visualAssets, captionTrack, }) {
    const frame = useCurrentFrame();
    const relativeFrame = frame - startFrame;
    const durFrames = Math.max(1, Math.round(durationSec * fp));
    const entranceProgress = Math.min(1, Math.max(0, relativeFrame / TRANSITION_FRAMES));
    const exitProgress = Math.max(0, Math.min(1, (durFrames - relativeFrame) / TRANSITION_FRAMES));
    const holdStart = TRANSITION_FRAMES;
    const holdEnd = Math.max(TRANSITION_FRAMES + 1, durFrames - TRANSITION_FRAMES);
    const holdProgress = durFrames <= TRANSITION_FRAMES * 2
        ? 1
        : Math.min(1, Math.max(0, (relativeFrame - holdStart) / Math.max(1, holdEnd - holdStart)));
    const opacity = interpolate(entranceProgress, [0, 1], [0, 1]) *
        interpolate(holdProgress, [0, 1], [0.9, 1]) *
        interpolate(exitProgress, [0, 1], [1, 0]);
    const translateY = interpolate(entranceProgress, [0, 1], [30, 0]) + interpolate(exitProgress, [0, 1], [0, -25]);
    const scale = interpolate(entranceProgress, [0, 1], [0.88, 1]);
    const typeLabel = (scene.badge || scene.type || 'Segment').toUpperCase();
    const directiveText = scene.directives?.highlight_text || scene.directives?.emotion_tone || '';
    const narrationText = scene.narration || scene.description || scene.headline || '';
    return (_jsxs(AbsoluteFill, { style: {
            opacity,
            transform: `translateY(${translateY}px) scale(${scale})`,
            display: 'flex',
            flexDirection: 'column',
            justifyContent: 'center',
            alignItems: 'center',
            padding: '40px 56px',
            textAlign: isRTL ? 'right' : 'center',
            pointerEvents: 'none',
        }, children: [_jsxs("div", { style: {
                    position: 'absolute',
                    top: 120,
                    left: isRTL ? 'auto' : 60,
                    right: isRTL ? 60 : 'auto',
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 10,
                    background: 'rgba(0,0,0,0.5)',
                    border: `1px solid ${theme.accent}44`,
                    borderRadius: 999,
                    padding: '12px 26px',
                    backdropFilter: 'blur(16px)',
                    fontSize: 13,
                    fontWeight: 900,
                    letterSpacing: 0.18,
                    textTransform: 'uppercase',
                    color: theme.accent,
                    boxShadow: `0 0 32px ${theme.accent}33`,
                }, children: [_jsx("span", { style: {
                            width: 8,
                            height: 8,
                            borderRadius: '50%',
                            background: theme.accent,
                            boxShadow: `0 0 16px ${theme.accent}`,
                            animation: 'pulse 2s ease-in-out infinite',
                        } }), typeLabel, _jsxs("span", { style: { opacity: 0.6, fontSize: 11, fontWeight: 700 }, children: ["\uFFFD ", durationSec, "s"] })] }), _jsxs("div", { style: {
                    position: 'absolute',
                    top: 120,
                    right: isRTL ? 60 : 'auto',
                    left: isRTL ? 'auto' : 60,
                    fontWeight: 900,
                    fontSize: 15,
                    color: 'rgba(255,255,255,0.55)',
                    letterSpacing: '-0.02em',
                }, children: [String(sceneIndex + 1).padStart(2, '0'), " / ", String(totalScenes).padStart(2, '0')] }), _jsxs("div", { style: {
                    maxWidth: 820,
                    width: '100%',
                    background: theme.panel,
                    border: `1px solid rgba(255,255,255,0.16)`,
                    borderRadius: 32,
                    padding: 48,
                    backdropFilter: 'blur(24px)',
                    boxShadow: '0 40px 100px rgba(0,0,0,0.5)',
                    position: 'relative',
                    zIndex: 3,
                }, children: [directiveText && (_jsxs("div", { style: {
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 10,
                            background: `rgba(${hexToRgb(theme.accent)},0.14)`,
                            border: `1px solid ${theme.accent}55`,
                            borderRadius: 14,
                            padding: '10px 24px',
                            marginBottom: 32,
                            fontSize: 14,
                            fontWeight: 900,
                            letterSpacing: 0.14,
                            textTransform: 'uppercase',
                            color: theme.accent,
                            alignSelf: 'flex-start',
                            boxShadow: `0 0 24px ${theme.accent}22`,
                        }, children: [_jsx("span", { style: { width: 8, height: 8, borderRadius: '50%', background: theme.accent } }), directiveText] })), _jsx("h1", { style: {
                            fontSize: 56,
                            fontWeight: 900,
                            lineHeight: 1.05,
                            letterSpacing: '-0.03em',
                            color: '#ffffff',
                            marginBottom: 26,
                            maxWidth: 800,
                            textShadow: '0 10px 40px rgba(0,0,0,0.6)',
                        }, children: scene.headline }), _jsx("div", { style: {
                            width: 140,
                            height: 7,
                            borderRadius: 4,
                            background: `linear-gradient(90deg, ${theme.accent}ff, ${theme.glow}, ${theme.accent}ff)`,
                            boxShadow: `0 0 28px ${theme.accent}77`,
                            marginBottom: 32,
                        } }), _jsx("p", { style: {
                            fontSize: 36,
                            fontWeight: 700,
                            lineHeight: 1.5,
                            color: '#ffffff',
                            maxWidth: 800,
                            marginBottom: 32,
                            textShadow: '0 6px 24px rgba(0,0,0,0.4)',
                        }, children: narrationText }), scene.claim && (_jsx("div", { style: {
                            fontSize: 20,
                            fontWeight: 600,
                            lineHeight: 1.5,
                            color: theme.textDim,
                            maxWidth: 760,
                            marginBottom: 28,
                            textShadow: '0 4px 16px rgba(0,0,0,0.3)',
                        }, children: scene.claim })), visualAssets && visualAssets.length > 0 && (_jsx("div", { style: {
                            display: 'flex',
                            gap: 18,
                            flexWrap: 'wrap',
                            justifyContent: 'center',
                            marginTop: 12,
                        }, children: visualAssets.slice(0, 3).map((v, i) => (_jsxs("div", { style: { position: 'relative', display: 'inline-flex', flexDirection: 'column', alignItems: 'center' }, children: [_jsx("img", { src: v.path, alt: v.attribution || 'visual', style: {
                                        maxHeight: 180,
                                        maxWidth: 260,
                                        borderRadius: 24,
                                        opacity: v.status === 'verified' ? 1 : 0.7,
                                        boxShadow: '0 24px 60px rgba(0,0,0,0.6)',
                                        border: `1px solid rgba(255,255,255,0.16)`,
                                        objectFit: 'cover',
                                    } }), v.attribution && (_jsx("div", { style: {
                                        marginTop: 10,
                                        fontSize: 11,
                                        fontWeight: 700,
                                        color: theme.textDim,
                                        textAlign: 'center',
                                        maxWidth: 260,
                                        lineHeight: 1.3,
                                    }, children: v.attribution }))] }, i))) })), scene.source && (_jsxs("div", { style: {
                            marginTop: 36,
                            paddingTop: 24,
                            borderTop: `1px solid rgba(255,255,255,0.14)`,
                            display: 'flex',
                            alignItems: 'center',
                            gap: 14,
                            fontSize: 13,
                            fontWeight: 800,
                            letterSpacing: 0.12,
                            textTransform: 'uppercase',
                            color: 'rgba(255,255,255,0.6)',
                        }, children: [_jsx("span", { style: { width: 6, height: 6, borderRadius: '50%', background: theme.accent } }), "Source: ", scene.source, scene.sourceUrl && (_jsxs("span", { style: { fontSize: 12, fontWeight: 600, opacity: 0.7 }, children: ["\uFFFD ", new URL(scene.sourceUrl).hostname] }))] }))] }), captionTrack && captionTrack.wordTimings && captionTrack.wordTimings.length > 0 && (_jsx(KaraokeCaptions, { wordTimings: captionTrack.wordTimings, frame: frame, startFrame: startFrame, durationSec: durationSec, fp: fp, isRTL: isRTL, theme: theme, language: captionTrack.language, source: captionTrack.source }))] }));
}
function KaraokeCaptions({ wordTimings, frame, startFrame, durationSec, fp, isRTL, theme, language, source, }) {
    const relativeSec = (frame - startFrame) / fp;
    const relativeMs = relativeSec * 1000;
    const activeWord = wordTimings.find((w) => relativeMs >= w.startMs && relativeMs <= w.endMs);
    const words = wordTimings.map((w, i) => {
        const isActive = activeWord?.word === w.word;
        return (_jsx("span", { style: {
                fontWeight: isActive ? 900 : 600,
                color: isActive ? theme.accent : 'rgba(255,255,255,0.75)',
                fontSize: isActive ? 30 : 24,
                margin: '0 3px',
                transition: 'all 0.15s ease',
                textShadow: isActive
                    ? `0 0 24px ${theme.accent}aa, 0 4px 14px rgba(0,0,0,0.4)`
                    : '0 2px 8px rgba(0,0,0,0.3)',
            }, children: w.word }, i));
    });
    return (_jsxs("div", { style: {
            position: 'absolute',
            bottom: 140,
            left: 60,
            right: 60,
            background: 'rgba(0,0,0,0.7)',
            borderRadius: 28,
            padding: '24px 36px',
            backdropFilter: 'blur(16px)',
            border: `1px solid ${theme.accent}55`,
            pointerEvents: 'none',
            boxShadow: `0 0 56px ${theme.accent}22`,
            maxWidth: 920,
            margin: '0 auto',
        }, children: [_jsxs("div", { style: {
                    fontSize: 12,
                    fontWeight: 900,
                    color: theme.accent,
                    marginBottom: 14,
                    textTransform: 'uppercase',
                    letterSpacing: 0.2,
                    textAlign: 'center',
                }, children: [(source === 'verified' ? 'Verified' : 'Estimated'), " transcript \uFFFD ", (language ?? 'en').toUpperCase()] }), _jsx("div", { style: {
                    fontSize: 30,
                    fontWeight: 700,
                    lineHeight: 1.7,
                    color: '#ffffff',
                    textAlign: 'center',
                    textShadow: '0 4px 16px rgba(0,0,0,0.35)',
                }, children: words })] }));
}
function FooterStrip({ title, language, targetDuration, isRTL, theme }) {
    return (_jsxs("div", { style: {
            display: 'flex',
            alignItems: 'center',
            gap: 16,
            width: '100%',
            justifyContent: isRTL ? 'flex-end' : 'flex-start',
        }, children: [_jsx("div", { style: {
                    height: 44,
                    width: 5,
                    borderRadius: 4,
                    background: `linear-gradient(180deg, ${theme.accent}, ${theme.accent}77)`,
                    boxShadow: `0 0 20px ${theme.accent}88`,
                } }), _jsx("div", { style: {
                    fontWeight: 900,
                    fontSize: 18,
                    letterSpacing: '-0.02em',
                    color: '#ffffff',
                    textShadow: '0 2px 10px rgba(0,0,0,0.5)',
                }, children: title }), _jsxs("div", { style: {
                    fontSize: 12,
                    fontWeight: 800,
                    color: 'rgba(255,255,255,0.6)',
                    letterSpacing: 0.18,
                    textTransform: 'uppercase',
                }, children: [language.toUpperCase(), " \uFFFD ", targetDuration, "s \uFFFD AI-PVG"] })] }));
}
function hexToRgb(hex) {
    const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    return result ? `${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)}` : '0, 212, 255';
}
