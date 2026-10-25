import { defineConfig } from "vite";

export default defineConfig({
  server: {
    // The app loads this address during `myphp dev` (devUrl in myphp.config.ts).
    port: 5173,
    strictPort: true,
    // The development app and the packaged builds are not the page's.
    watch: { ignored: ["**/.myphp/**", "**/build/**"] },
  },
});
