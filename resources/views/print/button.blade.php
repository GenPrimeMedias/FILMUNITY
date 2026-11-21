<button type="button" onclick="Promise.all([document.fonts.ready,...Array.from(document.images).map(image=>image.decode().catch(()=>{}))]).then(()=>window.print())">Print / Save as PDF</button>
