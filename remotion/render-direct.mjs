import { renderMedia, getCompositions } from '@remotion/renderer';
import { resolve } from 'path';

const bundlePath = resolve(process.cwd(), 'remotion/dist/VideoScript.js');
const outPath = resolve(process.cwd(), 'storage/app/video_out/verified_direct.mp4');

try {
    const compositions = await getCompositions(bundlePath, { inputProps: {} });
    const comp = compositions.find(c => c.id === 'VideoScript') || compositions[0];
    if (!comp) throw new Error('No composition found');
    await renderMedia({
        composition: comp,
        serveUrl: 'file://' + bundlePath,
        outputLocation: outPath,
        inputProps: { scenes: [{order:1,type:'intro',duration:5,headline:'Test',description:'Direct',narration:'Verify',visual_keywords:['test']}], title: 'Direct', language: 'en', targetDuration: 5 },
        overwrite: true,
        chromiumOptions: { executablePath: process.env.REMOTION_CHROME_EXECUTABLE || undefined },
    });
    console.log('RENDER OK: ' + outPath);
} catch (e) {
    console.error('RENDER FAILED: ' + e.message);
    process.exit(1);
}
