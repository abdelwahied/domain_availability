/**
 * @file
 * Progressive enhancement for the domain availability results.
 *
 * The form works fully without this file: the Form API renders results
 * server-side over AJAX, and everything here is decoration. That is deliberate
 * — availability data must not depend on JavaScript succeeding.
 */

((Drupal, once) => {
  /**
   * Staggers the entrance of result cards so a grid resolves as a wave.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.domainAvailabilityResults = {
    attach(context) {
      once('domain-availability-card', '.domain-availability-card', context).forEach(
        (card, index) => {
          card.classList.add('domain-availability-card--new');
          card.style.animationDelay = `${Math.min(index * 25, 400)}ms`;
        },
      );
    },
  };
})(Drupal, once);
