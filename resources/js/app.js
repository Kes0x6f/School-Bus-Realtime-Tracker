import './bootstrap';

console.log('app.js loaded');

window.addEventListener('load', () => {
    console.log('Window loaded, Echo =', window.Echo);

    Echo.channel("vehicle.1")
    .listen(".location.updated", (event) => {
        console.log("Vehicle update received:", event);
    });
});
