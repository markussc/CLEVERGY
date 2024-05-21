/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */

import $ from 'jquery';
// things on "window" become global variables
window.$ = window.jQuery = $;

import 'foundation-sites';
import '@fortawesome/fontawesome-free';

import 'foundation-sites/dist/css/foundation-float.min.css';
import '@fortawesome/fontawesome-free/css/all.min.css';
import './styles/app.css';
import './styles/owfont-regular.min.css';

// base.html.twig
$(document).foundation();

function showSpinner()
{
    $("body").addClass("loading");
}
window.showSpinner = showSpinner;

function hideSpinner()
{
    $("body").removeClass("loading");
}
window.hideSpinner = hideSpinner;

// contentHomepage.html.twig
function loadDeviceModal(title, lead, content) {
    $("#deviceModalTitle").html(title);
    $("#deviceModalLead").html(lead);
    $("#deviceModalContent").html(content);
}
window.loadDeviceModal = loadDeviceModal;

function getTimerValue()
{
    return $("input:radio[name='mystromTimer']:checked").val();
}
window.getTimerValue = getTimerValue;

function getCarTimerValue()
{
    return "cartimer_" + $("#cartimer_car").val() + "_" + $("#cartimer_deadline").val() + "_" + $("#cartimer_percent").val();
}
window.getCarTimerValue = getCarTimerValue;

// contentDetails.html.twig
function menuToggle(btn, elemId)
{
    if (btn.hasClass('hollow')) {
        // is inactive
        $('.content').hide();
        $('.menu-btn').addClass('hollow');
        btn.removeClass('hollow');
        $('#'+elemId+'_container').toggle();
    } else {
        // is active
        $('.content').hide();
        $('.menu-btn').addClass('hollow');
    }
}
window.menuToggle = menuToggle;
