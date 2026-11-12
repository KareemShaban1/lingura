import type { MetadataRoute } from "next";

export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "LinguaForge",
    short_name: "LinguaForge",
    description: "Your personal AI language school",
    start_url: "/home",
    display: "standalone",
    background_color: "#fbfaf7",
    theme_color: "#0f6e6e",
    icons: [
      { src: "/icon-192.png", sizes: "192x192", type: "image/png" },
      { src: "/icon-512.png", sizes: "512x512", type: "image/png" },
      { src: "/icon-512.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
    ],
  };
}
