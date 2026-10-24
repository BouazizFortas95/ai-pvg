import { bundle } from '@remotion/bundler';
const outDir = await bundle(resolve('src/VideoScript.tsx'), {
    webpackOption: {
        externals: { react: 'React', 'react-dom': 'ReactDOM' },
    },
    onBundleProgress: () => {},
});
console.log('Bundled to', outDir);
