import socket,struct,asyncio,multiprocessing

data = {
    "status": 0,
    "power": 0,
    "temp": 20,
    "soc": 0
}

class NelinorClient:
    def receive(self, ip):
        asyncio.run(self.receiver(ip))
        return

    @staticmethod
    async def receiver(ip):
        global data
        client_socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        client_socket.connect((ip, 9865))
        buf = client_socket.recv(71, socket.MSG_WAITALL) # note: for some reason, the very first transmitted frame is shorter than any following. we simply receive and dismiss it
        while 1:
            buf = client_socket.recv(74, socket.MSG_WAITALL)
            print(buf)
            chargingValue = struct.unpack_from('H', buf, 63)[0]
            charging = min(2300, max(-2300, int(chargingValue)))
            chargingDirValue = struct.unpack_from('b', buf, 69)[0]
            chargingDir = int(chargingDirValue)
            if chargingDir == 52:
                charging = -1 * charging
            status = struct.unpack_from('B', buf, 54)[0]
            tempValue = int(struct.unpack_from('H', buf, 72)[0]) / 100
            temp = min(100, max(-100, tempValue))
            batteryLevelValue = int(struct.unpack_from('H', buf, 7)[0])
            batteryLevel = min(7000, max(0, batteryLevelValue))
            if int(batteryLevel) > 0:
                soc = int(100 / 7000 * batteryLevel)
            else:
                soc = 0
            data["status"] = status
            data["power"] = charging
            data["temp"] = temp
            data["soc"] = soc
            print(data)
