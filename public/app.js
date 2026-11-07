document.addEventListener('submit', event => {
    const question = event.target.dataset.confirm;
    if (question && !window.confirm(question)) event.preventDefault();
});
