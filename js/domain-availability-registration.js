/**
 * @file
 * Marks the certificate field required while the applicant is a company.
 *
 * The other company fields do this with #states, but a managed_file cannot:
 * its preprocess copies only the id and class off #attributes, so the
 * data-drupal-states attribute never reaches the DOM. The requirement itself is
 * enforced server-side in DomainRegistrationRequestForm::validateForm(); this
 * only keeps the asterisk honest.
 */

((Drupal, once, drupalSettings) => {
  /**
   * Toggles the required marker on the certificate label.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.domainAvailabilityCertificateRequired = {
    attach(context) {
      const requiredFor =
        drupalSettings.domainAvailability &&
        drupalSettings.domainAvailability.certificateRequiredFor;

      if (!requiredFor) {
        return;
      }

      once(
        'da-certificate-required',
        '.domain-availability-certificate',
        context,
      ).forEach((wrapper) => {
        const item = wrapper.closest('.js-form-item, .form-item');
        const label = item && item.querySelector('label');
        const radios = document.querySelectorAll(
          'input[name="applicant_type"]',
        );

        if (!label || !radios.length) {
          return;
        }

        const sync = () => {
          const checked = document.querySelector(
            'input[name="applicant_type"]:checked',
          );
          const on = !!checked && checked.value === requiredFor;

          label.classList.toggle('js-form-required', on);
          label.classList.toggle('form-required', on);
        };

        radios.forEach((radio) => radio.addEventListener('change', sync));
        sync();
      });
    },
  };
})(Drupal, once, drupalSettings);
