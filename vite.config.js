import { defineConfig } from 'vite';

export default defineConfig({
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        manifest: 'manifest.json',
        rollupOptions: {
            input: {
                editor: 'resources/js/editor/editor.js',
            },
            output: {
                entryFileNames: 'editor.js',
                assetFileNames: (asset) => (asset.name?.endsWith('.css') ? 'editor.css' : 'assets/[name][extname]'),
            },
        },
    },
});
