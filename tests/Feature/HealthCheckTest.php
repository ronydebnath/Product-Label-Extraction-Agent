<?php

// The compose healthcheck and any load balancer hit this route; it must answer without
// depending on the database or the queue, or a slow dependency would take the web tier down.
it('answers the health check', function () {
    $this->get('/up')->assertOk();
});
