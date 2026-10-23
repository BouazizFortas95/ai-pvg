import { readFileSync } from 'fs';
import { renderMedia, selectComposition } from '@remotion/renderer';
async function run() {
    const args = process.argv.slice(2);
    let inputPath = 'payload.json';
    let outputPath = 'output.mp4';
    for (let i = 0; i < args.length; i++) {
        if (args[i] === '--input' && args[i + 1])
            inputPath = args[i + 1];
        if (args[i] === '--output' && args[i + 1])
            outputPath = args[i + 1];
    }
    const payloadStr = readFileSync(inputPath, 'utf8');
    const payload = JSON.parse(payloadStr);
    const scenes = payload.scenes || [];
    const durationFrames = Math.round((payload.target_duration || 30) * 30);
    const bundleDir = process.env.REMOTION_BUNDLE_DIR || './src';
    const compId = 'VideoScript';
    const onBeforeRender = () => console.log('Rendering started');
    const onProgress = ({ progress }) => console.log('Progress:', Math.round(progress * 100) + '%');
    const result = await renderMedia({
        composition: await selectComposition({
            serveUrl: 'http://localhost:3000',
            id: compId,
            inputProps: { scenes, title: payload.title, language: payload.language || 'en', targetDuration: payload.target_duration || 30, template: payload.template || 'cyber' },
        }),
        serveUrl: '',
        codec: 'h264',
        outputLocation: outputPath,
        inputProps: { scenes, title: payload.title, language: payload.language || 'en', targetDuration: payload.target_duration || 30, template: payload.template || 'cyber' },
        onProgress,
    });
    console.log('Render complete:', outputPath);
}
run().catch((e) => {
    console.error('Render error:', e);
    process.exit(1);
});
