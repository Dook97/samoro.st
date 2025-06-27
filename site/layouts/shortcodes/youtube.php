{{- $id := .Get 0 -}}

<div>
  <script>document.currentScript.parentElement.style.display = 'none';</script>
  <a href="https://www.youtube.com/watch?v={{ $id }}" target="_blank" rel="noopener">
    <div style="width: 100%; aspect-ratio: 16/9; background-color: black; overflow: hidden; display: flex; justify-content: center; align-items: center;">
      <img src="/img/youtube-ico.svg" style="width: 15%;">
    </div>
  </a>
</div>

<div style="display: none; position: relative; aspect-ratio: 16/9; overflow: hidden; background-color: black;">
  <iframe
    src="https://www.youtube.com/embed/{{ $id }}"
    frameborder="0"
    allowfullscreen
    loading="lazy"
    style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;">
  </iframe>
  <script>
    const container = document.currentScript.parentElement;
    const iframe = container.querySelector('iframe');
    const fallbackContainer = container.previousElementSibling;

    let loaded = false;

    container.style.display = 'block';
    iframe.onload = () => {
      loaded = true;
    };

    setTimeout(() => {
      if (!loaded) {
        container.style.display = 'none';
        fallbackContainer.style.display = 'block';
      }
    }, 3000);
  </script>
</div>
