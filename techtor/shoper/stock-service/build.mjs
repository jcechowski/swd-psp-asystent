import { build } from 'esbuild';

const watch = process.argv.includes('--watch');

await build({
  entryPoints: ['src/widget/index.ts'],
  bundle: true,
  minify: !watch,
  format: 'iife',
  globalName: 'TechtorWidget',
  target: ['es2020'],
  outfile: 'public/widget.js',
  ...(watch ? {
    plugins: [{
      name: 'rebuild-notify',
      setup(build) {
        build.onEnd(result => {
          if (result.errors.length === 0) console.log(`[${new Date().toLocaleTimeString()}] widget.js rebuilt`);
        });
      },
    }],
    context: undefined,
  } : {}),
});

if (!watch) console.log('widget.js built successfully');
