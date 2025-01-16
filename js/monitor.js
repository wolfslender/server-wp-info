jQuery(document).ready(function($) {
    function getUsageClass(value) {
        if (value > 70) return 'high-usage';
        if (value > 30) return 'medium-usage';
        return 'low-usage';
    }

    function updateResourceInfo() {
        $.ajax({
            url: monitorAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_realtime_data',
                nonce: monitorAjax.nonce
            },
            timeout: 5000,
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // Actualizar memoria
                    $('#memory-used').text(data.memory.used);
                    $('#memory-limit').text(data.memory.limit);
                    $('#memory-percentage').text(data.memory.percentage);
                    $('#memory-bar').css('width', data.memory.percentage + '%')
                        .attr('class', 'meter-bar ' + getUsageClass(data.memory.percentage));
                    
                    // Actualizar CPU
                    $('#cpu-percentage').text(data.cpu.percentage);
                    $('#cpu-bar').css('width', data.cpu.percentage + '%')
                        .attr('class', 'meter-bar ' + getUsageClass(data.cpu.percentage));
                    
                    // Actualizar plugins
                    let pluginsHtml = '';
                    data.plugins.forEach(function(plugin) {
                        pluginsHtml += `
                            <tr>
                                <td>${plugin.name}</td>
                                <td>${plugin.version}</td>
                                <td class="${getUsageClass(plugin.memory * 10)}">${plugin.memory}</td>
                                <td class="${getUsageClass(plugin.cpu_impact)}">${plugin.cpu_impact}</td>
                                <td>${plugin.time}</td>
                                <td>${plugin.size}</td>
                            </tr>
                        `;
                    });
                    $('#plugins-list').html(pluginsHtml);

                    // Actualizar servicios
                    let servicesHtml = '';
                    data.services.forEach(function(service) {
                        servicesHtml += `
                            <tr>
                                <td>${service.name}</td>
                                <td>${service.pid}</td>
                                <td>${service.memory} MB</td>
                            </tr>
                        `;
                    });
                    $('#services-list').html(servicesHtml);

                    // Actualizar uso de memoria del servidor
                    $('#server-memory-used').text(data.server.memory.used);
                    $('#server-memory-total').text(data.server.memory.total);
                    $('#server-memory-percentage').text(data.server.memory.percentage);
                    $('#server-memory-bar').css('width', data.server.memory.percentage + '%')
                        .attr('class', 'meter-bar ' + getUsageClass(data.server.memory.percentage));

                    // Actualizar uso del CPU del servidor
                    $('#server-cpu-percentage').text(data.server.cpu.percentage);
                    $('#server-cpu-bar').css('width', data.server.cpu.percentage + '%')
                        .attr('class', 'meter-bar ' + getUsageClass(data.server.cpu.percentage));
                }
            },
            error: function(xhr, status, error) {
                console.log('Error:', error);
            }
        });
    }

    // Actualizar cada 10 segundos
    setInterval(updateResourceInfo, 10000);
    updateResourceInfo();
});
