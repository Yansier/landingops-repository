(() => {
  "use strict";

  const runtime = window.__LANDINGOPS_CONFIG__;
  const headline = document.getElementById("headline");
  const status = document.getElementById("status");
  const download = document.getElementById("download");

  if (!runtime || runtime.contract !== "lts-public-config/1.0") {
    headline.textContent = "配置不可用";
    status.textContent = "公开配置缺失或版本不兼容。";
    download.hidden = true;
    return;
  }

  headline.textContent = runtime.config.custom.headline;
  download.href = runtime.config.links.download_url;
  download.dataset.eventEnabled = String(runtime.config.events.download.enabled);
  document.documentElement.dataset.pixelCount = String(runtime.config.pixel_ids.length);
  status.textContent = `配置 revision ${runtime.revision} 已离线加载。`;
})();
