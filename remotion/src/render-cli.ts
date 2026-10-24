import { readFileSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';
import { bundle } from '@remotion/bundler';
import { renderMedia, selectComposition } from '@remotion/renderer';

const currentDir = dirname(fileURLToPath(import.meta.url));

type Payload = {
  scenes?: unknown[];
  title?: string;
  language?: string;
  target_duration?: number;
  template?: string;
  audioUrl?: string;
};

async function run() {
  const args = process.argv.slice(2);
  let inputPath = 'payload.json';
  let outputPath = 'output.mp4';

  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--input' && args[i + 1]) inputPath = args[i + 1];
    if (args[i] === '--output' && args[i + 1]) outputPath = args[i + 1];
  }

  const payload = JSON.parse(readFileSync(inputPath, 'utf8')) as Payload;
  const props = {
    scenes: payload.scenes || [],
    title: payload.title || 'AI-PVG',
    language: payload.language || 'en',
    targetDuration: payload.target_duration || 15,
    template: payload.template || 'cyber',
    ...(payload.audioUrl ? { audioUrl: payload.audioUrl } : {}),
  };

  const entryPoint = process.env.REMOTION_ENTRY_POINT || resolve(currentDir, '../src/index.ts');
  const publicDir = resolve(currentDir, '../public');
  const port = Number(process.env.REMOTION_PORT || 3099);

  console.log('Bundling entry point:', entryPoint);
  const serveUrl = await bundle({
    entryPoint,
    publicDir,
    onProgress: (progress: number) => {
      console.log('Bundling:', Math.round(progress * 100) + '%');
    },
  });
  console.log('Bundle URL:', serveUrl);

  const composition = await selectComposition({
    serveUrl,
    id: 'VideoScript',
    inputProps: props,
    port,
  });

  await renderMedia({
    composition,
    serveUrl,
    codec: 'h264',
    outputLocation: outputPath,
    inputProps: props,
    port,
    onProgress: ({ progress }: { progress: number }) => {
      console.log('Progress:', Math.round(progress * 100) + '%');
    },
  });

  console.log('Render complete:', outputPath);
}

run().catch((error) => {
  console.error('Render error:', error);
  process.exit(1);
});
